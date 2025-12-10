#!/usr/bin/perl

use strict;
use warnings;

use Net::DBus;
use Time::HiRes qw(sleep);

use HTTP::Tiny;
use URI::Escape qw(uri_escape_utf8);

# ------------------------------------------------------------------------------
# Configuration (edit as appropriate)
# ------------------------------------------------------------------------------

my $SLEEP_TIME  = 5;    # seconds between scans (overridden by arg 1)
my $MOTHERSHIP  = 'http://localhost/tracker.php';
my $OUT_FILE    = 'scan.txt';

# ------------------------------------------------------------------------------
# State
# ------------------------------------------------------------------------------

my %devices;
my $SENSOR_MAC  = 'unknown';
my $LOCATION    = 'unknown';
my $VERBOSE     = 1;

# D-Bus / BlueZ constants
my $BLUEZ_SERVICE   = 'org.bluez';
my $OBJMGR_IFACE    = 'org.freedesktop.DBus.ObjectManager';
my $PROPS_IFACE     = 'org.freedesktop.DBus.Properties';
my $ADAPTER_IFACE   = 'org.bluez.Adapter1';
my $DEVICE_IFACE    = 'org.bluez.Device1';

# ------------------------------------------------------------------------------
# Arguments
# ------------------------------------------------------------------------------

my $argc = scalar @ARGV;

if ( $argc != 2 ) {
    print "usage: $0 <sleep-seconds> <location>\n";
    exit(0);
}

$SLEEP_TIME = $ARGV[0];
$LOCATION   = $ARGV[1];

# ------------------------------------------------------------------------------
# Main loop
# ------------------------------------------------------------------------------

# Connect to the system bus and BlueZ once, reuse handles
my $bus    = Net::DBus->system;                     # BlueZ lives on system bus 
my $bluez  = $bus->get_service($BLUEZ_SERVICE);

while (1) {

    if ($VERBOSE) {
        print ">>> preparing Bluetooth scan via D-Bus...\n";
    }

    # Get all managed objects (adapters + devices)
    my $objects = get_managed_objects($bluez);

    # Find default adapter and its MAC
    my ($adapter_path, $adapter_addr) = find_adapter($bluez, $objects);

    if (!$adapter_path) {
        warn "No Bluetooth adapter (Adapter1) found via D-Bus; skipping this cycle.\n";
        sleep($SLEEP_TIME);
        next;
    }

    $SENSOR_MAC = $adapter_addr if defined $adapter_addr;

    if ($VERBOSE) {
        print ">>> using adapter $adapter_path (MAC=$SENSOR_MAC)\n";
        print ">>> starting discovery...\n";
    }

    # Make sure adapter is powered, then start discovery
    power_on_adapter($bluez, $adapter_path);
    start_discovery($bluez, $adapter_path);

    # Let discovery run a bit to collect devices
    my $DISCOVERY_TIME = 5;   # seconds of active discovery
    sleep($DISCOVERY_TIME);

    # Refresh managed objects after discovery
    $objects = get_managed_objects($bluez);

    # Optionally stop discovery (BlueZ will remember devices for a while) 
    stop_discovery($bluez, $adapter_path);

    # Collect devices (MAC => name)
    %devices = gather_devices_for_adapter($objects, $adapter_path);

    my $count = scalar keys %devices;

    if ($VERBOSE) {
        print ">>> Inquiry found $count devices, sending to mothership...";
        foreach my $mac (sort keys %devices) {
            my $name = $devices{$mac};
            print "\n   * found device mac=$mac;name=$name";
        }
        print "\n";
    }

    send_inquiry(\%devices, $LOCATION, $SENSOR_MAC, $OUT_FILE, $MOTHERSHIP, $VERBOSE);

    %devices = ();

    if ($VERBOSE) {
        print ">>> sleeping for $SLEEP_TIME second(s)...";
    }

    sleep($SLEEP_TIME);

    if ($VERBOSE) {
        print "(done)\n";
    }
}

# ------------------------------------------------------------------------------
# Helper: get all BlueZ managed objects (ObjectManager.GetManagedObjects)
# ------------------------------------------------------------------------------

sub get_managed_objects {
    my ($bluez_service) = @_;

    my $objmgr = $bluez_service->get_object('/', $OBJMGR_IFACE);
    my $objects = $objmgr->GetManagedObjects();   # returns a{oa{sa{sv}}} 

    return $objects || {};
}

# ------------------------------------------------------------------------------
# Helper: find an adapter and its MAC address
#   returns (adapter_path, adapter_mac)
# ------------------------------------------------------------------------------

sub find_adapter {
    my ($bluez_service, $objects) = @_;

    # Prefer /org/bluez/hci0 if present, else first Adapter1
    my $chosen_path;
    foreach my $path (sort keys %{$objects}) {
        my $ifaces = $objects->{$path};
        next unless exists $ifaces->{$ADAPTER_IFACE};

        if ($path eq '/org/bluez/hci0') {
            $chosen_path = $path;
            last;
        }

        $chosen_path ||= $path;
    }

    return (undef, undef) unless $chosen_path;

    # Get Address property via org.freedesktop.DBus.Properties 
    my $props_obj = $bluez_service->get_object($chosen_path, $PROPS_IFACE);
    my $addr;
    eval {
        $addr = $props_obj->Get($ADAPTER_IFACE, 'Address');
    };
    if ($@) {
        warn "Warning: failed to read adapter Address property via D-Bus: $@\n";
    }

    return ($chosen_path, $addr);
}

# ------------------------------------------------------------------------------
# Helper: ensure adapter is powered on (Adapter1.Powered = true)
# ------------------------------------------------------------------------------

sub power_on_adapter {
    my ($bluez_service, $adapter_path) = @_;

    my $props_obj = $bluez_service->get_object($adapter_path, $PROPS_IFACE);

    # Check current Powered state first
    my $powered = 0;
    eval {
        $powered = $props_obj->Get($ADAPTER_IFACE, 'Powered');
    };
    if ($@) {
        warn "Warning: cannot read Powered property: $@\n";
    }

    return if $powered;

    eval {
        $props_obj->Set($ADAPTER_IFACE, 'Powered', 1);
    };
    if ($@) {
        warn "Warning: cannot set adapter Powered=1: $@\n";
    }
}

# ------------------------------------------------------------------------------
# Helper: start discovery (Adapter1.StartDiscovery)
# ------------------------------------------------------------------------------

sub start_discovery {
    my ($bluez_service, $adapter_path) = @_;

    my $adapter = $bluez_service->get_object($adapter_path, $ADAPTER_IFACE);

    eval {
        $adapter->StartDiscovery();   # scans for both Classic+LE devices 
    };
    if ($@) {
        warn "Warning: StartDiscovery failed: $@\n";
    }
}

# ------------------------------------------------------------------------------
# Helper: stop discovery (Adapter1.StopDiscovery)
# ------------------------------------------------------------------------------

sub stop_discovery {
    my ($bluez_service, $adapter_path) = @_;

    my $adapter = $bluez_service->get_object($adapter_path, $ADAPTER_IFACE);

    eval {
        $adapter->StopDiscovery();
    };
    if ($@) {
        # Not fatal; discovery will stop eventually anyway
        warn "Warning: StopDiscovery failed: $@\n";
    }
}

# ------------------------------------------------------------------------------
# Helper: gather devices belonging to this adapter
#   returns hash (MAC => Name/Alias)
#
# We look at all objects implementing org.bluez.Device1 and grab:
#   - Address (MAC)
#   - Alias or Name (friendly name) 
# ------------------------------------------------------------------------------

sub gather_devices_for_adapter {
    my ($objects, $adapter_path) = @_;

    my %found;

    foreach my $path (keys %{$objects}) {
        my $ifaces = $objects->{$path};

        next unless exists $ifaces->{$DEVICE_IFACE};

        my $dev_props = $ifaces->{$DEVICE_IFACE} || {};

        # Only count devices owned by this adapter
        if (exists $dev_props->{Adapter} && $dev_props->{Adapter} ne $adapter_path) {
            next;
        }

        my $mac  = $dev_props->{Address} || next;
        my $name = $dev_props->{Alias}
                   // $dev_props->{Name}
                   // 'Unknown';

        # Optional: if you want only "recently seen", you could require RSSI
        # my $rssi = $dev_props->{RSSI};
        # next unless defined $rssi;

        $found{$mac} = $name;
    }

    return %found;
}

# ------------------------------------------------------------------------------
# Helper: send inquiry data to mothership (modernized, uses HTTP::Tiny)
# ------------------------------------------------------------------------------

sub send_inquiry {
    my ($devices_ref, $location, $sensor_mac, $out_file, $mothership, $verbose) = @_;

    my $content = "";

    while (my ($mac, $name) = each %{$devices_ref}) {
        $content .= '&' if length($content) > 0;

        # URL-encode using URI::Escape
        my $enc_mac  = url_encode($mac);
        my $enc_name = url_encode($name);

        $content .= $enc_mac . '=' . $enc_name;
    }

    # append our location and our MAC
    $content .= '&location=' . url_encode($location);
    $content .= '&sensor='   . url_encode($sensor_mac);

    # write content to file (optional / debug)
    if (open(my $fh, '>', $out_file)) {
        print $fh $content;
        close($fh);
    } else {
        warn "Error opening file for write: $out_file\n";
    }

    # --- Modern HTTP POST using HTTP::Tiny ---

    my $http = HTTP::Tiny->new(
        timeout    => 10,
        verify_SSL => 1,   # set to 0 only if you *really* need to skip TLS verify
    );

    if ($verbose) {
        print ">>> sending data via HTTP::Tiny to $mothership...\n";
    }

    my $response = $http->post(
        $mothership,
        {
            headers => {
                'content-type' => 'application/x-www-form-urlencoded',
            },
            content => $content,
        }
    );

    if (!$response->{success}) {
        warn "HTTP POST to $mothership failed: $response->{status} $response->{reason}\n";
        if ($verbose && defined $response->{content}) {
            warn "Response body: $response->{content}\n";
        }
    } elsif ($verbose) {
        print ">>> mothership replied: $response->{status} $response->{reason}\n";
    }
}

# ------------------------------------------------------------------------------
# Helper: very small URL-encoding util
# ------------------------------------------------------------------------------

sub url_encode {
    my ($s) = @_;
    return '' unless defined $s;

    # uri_escape_utf8 handles UTF-8 properly and percent-encodes non-safe chars
    return uri_escape_utf8($s);
}
