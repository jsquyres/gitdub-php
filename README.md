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

1. Install `gitdub.php` in a docroot.
1. Edit `gitdub.php` to reflect the config you want.
1. Config your web server to deny access to the gitdub state tree.  For example, add a `.htaccess` file with the following:
```
## make sure no one gets to the .gitdub-php dir
<Files ~ "^\.gitdub-php">
    Order deny,allow
    Deny from all
    Satisfy All
</Files>
```
1. Set your github repo webhook:
   1. To point to the URL of gitdub.php
   1. The content type should be "application/x-www-form-urlencoded"
1. You should see sensible results in the Github webhook logs indicating that gitdub.php is working
