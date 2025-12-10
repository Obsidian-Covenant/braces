# braces

## Modernization Covenant

This is a refactored and modernized edition of **braces**, a Bluetooth tracking utility.

Key changes:

- ✅ Dropped the deprecated hcitool
- ✅ curl replaced by HTTP:Tiny perl library
- ✅ Fixed URL encoding
- ✅ Refactored PHP files
- ✅ Refactored SQL file
- ✅ Refactored code to be used with latest gcc version

### Installation

#### Dependencies

Runtime dependencies:
```
bluez imagemagick mariadb nginx perl perl-uri perl-net-dbus php-fpm
```

#### From source

```shell
git clone https://github.com/Obsidian-Covenant/braces.git
cd braces
cp -rf html/*.php html/images /usr/share/nginx/html/
sudo systemctl enable --now nginx
sudo systemctl enable --now php-fpm
```

**Web server initialization**

Be sure to have atleast the following settings in **/etc/nginx/nginx.conf** in `http`:
```conf
    server {
        listen       80;
        server_name  localhost;

        root   /usr/share/nginx/html;
        index  index.php index.html index.htm;

        location / {
            try_files $uri $uri/ =404;
        }

        location ~ \.php$ {
            fastcgi_pass   unix:/run/php-fpm/php-fpm.sock;
            fastcgi_param  SCRIPT_FILENAME  $document_root$fastcgi_script_name;
            include        fastcgi_params;
        }
    }
```

In **/etc/php/php.ini** be sure to have at least the following settings uncommented:
```ini
cgi.fix_pathinfo=0
extension=mysqli
extension=pdo_mysql
```

If you have AppArmor installed, to prevent **Access Denied** error on the webpages, just run:
```
sudo aa-complain php-fpm
sudo systemctl restart apparmor
```

Finally, run:
```shell
sudo systemctl restart nginx
sudo systemctl restart php-fpm
```

**Database initialization**

Initialize MariaDB instance by running:
```shell
sudo mariadb-install-db --user=mysql --ldata=/var/lib/mysql
sudo systemctl enable --now mariadb
sudo mariadb -u root < init_db.sql
```

## Usage

If needed, edit the **braces.pl** file so that the mothership variable is set correctly. Use the braces script as
follows:
```
perl braces.pl <sleep> <location>
```
Make sure you know your location, don't just make one up.  The sleep time is 
seconds between each inquiry. A reasonable sleep time is 60 seconds.

Running `braces.pl` will generate a `scan.txt` debug file containing the POST data sent to the web server.

It is possible to access to the web view via browser by visiting http://localhost/index.php.
