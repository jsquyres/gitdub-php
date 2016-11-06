# gitdub.php

This is a simple re-spin of Matthias Vallentin's "gitdub", but in a
pure PHP form (the original "gitdub" project is a Ruby / Sinatra
daemon; see https://github.com/mavam/gitdub).

# Why does this project exist?

This project exists so that you can run a git email notifier in web
hosting environments where you are unable to run additional daemons.
For example, many simple web hosting packages allow arbitrary PHP web
pages, but do not allow running standalone processes (such as a Ruby /
Sinatra process) for more than a short period of time.

# Installation

Installation is comprised of two parts:

1. Installation on the web server
1. Installation at Github.com

## Installation on the web server

1. Copy `gitdub.php` to a folder in a docroot somewhere.
1. Copy the [`post-receive-email` script from the git source distribution](https://github.com/git/git/blob/master/contrib/hooks/post-receive-email) to:
   * A directory that is readable by the web server (e.g., your `$HOME` directory)
   * But the directory is **NOT** readable by clients (e.g., *outside* the docroot)
   * Make sure the script is marked as executable (e.g., `chmod +x post-receive-email`)
1. Copy the sample `gitdub-config.inc` to the same folder.
   * Edit the `gitdub-config.inc` to reflect the configuration that you want.  This usually means indicating the Github repos for which you want to receive incoming notifications.
   * Ensure to set the `post-receive-email` config value to the absolute path name to the `post-receive-email` script in the web server file system.
   * **OPTIONAL:** Configure your web server to deny client access to `gitdub-config.inc`.  For example, you can create a `.htaccess` file in the same directory to restrict access to your `gitdub-config.inc` containing:
```xml
<Files "gitdub-config.inc">
    Order allow,deny
    Deny from all
    Satisfy all
</Files>
```
1. Make a subdirectory for `gitdub.php` to store its state
   * This subdirectory must be in the same directory as `gitdub.php`.
   * The default name for this subdirectory -- as specified in `gitdub-config.inc` -- is `.gitdub-php`.  For example:
```
$ ls -la
total 36
drwxrwsr-x 3 jeff     web       4096 Aug 20 18:18 ./
drwxr-sr-x 3 jeff     web       4096 Aug  9 10:29 ../
-rw-r--r-- 1 jeff     web       3587 Aug 20 18:16 gitdub-config.inc
drwxrwsr-x 3 jeff     web       4096 Aug 20 18:07 .gitdub-php/
-rw-r--r-- 1 jeff     web      12051 Aug 20 18:16 gitdub.php
-rw-r--r-- 1 jeff     web        119 Aug 20 18:18 .htaccess
-rw-r--r-- 1 jeff     web         38 Aug  9 10:26 index.php
```
   * Ensure that your web user can write into this directory.
   * Configure your web server to deny client access to this entire directory.  **THIS IS NOT OPTIONAL!**  For example, you can create a `.htaccess` file in this state directory containing:
```xml
<FilesMatch ".*">
    Order deny,allow
    Deny from all
    Satisfy All
</Files>
```

## Installation at Github.com

1. On Github.com, create a custom webhook for your Git repo:
   * The URL should be the URL of your newly-installed `gitdub.php`.
   * The content type should be `application/x-www-form-urlencoded`.
   * Select to send "just the push event".
   * Make the webhook active.
1. When you create a webhook at Github.com, it should send a "ping" request to the webhook to make sure it is correct.
   * On your git repository's webhooks page, click on your webhook.
   * On the resulting page, scroll down to the "Recent Deliveries" section.  The very first delivery will be the "ping" event.
   * Click on the first delivery and check that the response code is 200.
   * If everything is working properly, the output body should say `Hello, Github ping!  I'm here!`.
1. Do a push to your Git repository.
   * If all goes well, a mail should arrive shortly with the notification of the push commits.
   * If you don't receive the notification email, check the "Recent deliveries" section of your webhook's configuration page and check the response body output from your delivery.
   * If all goes well, the last line of output should be `gitdub.php: post-receive-email successfully invoked`.  If you see this, it means that `gitdub.php` successfully invoked the `post-receive-email` script with the relevant git diff data.
