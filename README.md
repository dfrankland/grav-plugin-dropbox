# Dropbox Plugin for [Grav](http://getgrav.org) CMS
A highly alpha, yet working Dropbox plugin for Grav.

## *IMPORTANT: THIS PLUGIN IS NOT 100% TESTED, THERE IS A RISK FOR LOSS OF DATA. PLEASE, USE CAREFULLY; YOU ARE RESPONSIBLE FOR ANY LOSSES INCURRED BY USING THIS PLUGIN.*

### Made With
[**:space_invader: Grav CMS**](https://github.com/getgrav/grav) &mdash; Grav is a **Fast**, **Simple**, and **Flexible**, file-based Web-platform.

[**:arrows_counterclockwise: Dropbox SDK**](https://github.com/dropbox/dropbox-sdk-php) &mdash; A PHP library to access [Dropbox's HTTP-based API](http://dropbox.com/developers/core/docs).

[**:heart: Love**](https://www.youtube.com/watch?v=HEXWRTEbj1I) &mdash; "nature's way of tricking people into reproducing" -Urban Dictionary

### Requirements
* [PHP Oauth extension](https://secure.php.net/manual/en/oauth.installation.php)
* PHP 5.3+, [with 64-bit integers](http://stackoverflow.com/questions/864058/how-to-have-64-bit-integer-on-php).
* [PHP cURL extension](https://secure.php.net/manual/en/curl.installation.php) with SSL enabled (it's usually built-in).
* Must not be using [`mbstring.func_overload`](http://www.php.net/manual/en/mbstring.overload.php) to overload PHP's standard string functions.
* Grav cache enabled (using with cache disabled is currently untested)
* SSL
  * There are lots of options for getting a free certificate such as:
    1. Self-signing ([Nginx](https://www.digitalocean.com/community/tutorials/how-to-create-an-ssl-certificate-on-nginx-for-ubuntu-14-04) &amp; [Apache](https://www.digitalocean.com/community/tutorials/how-to-create-a-ssl-certificate-on-apache-for-ubuntu-14-04))
    2. [StartSSL](https://www.startssl.com/)
    3. [Cloudflare](https://www.cloudflare.com/ssl)
    4. [Et cetra](https://www.google.com/search?q=free+ssl+certificates)

### Getting Started
*[This is the official webhook guide from dropbox](https://www.dropbox.com/developers/webhooks/tutorial), but the instructions below are probably more helpful.*

1. Create an app for your Dropbox here: https://www.dropbox.com/developers/apps
  1. Click the blue "Create App" button in the top right.
  2. Next you will be prompted for the type of app you would like to create. Click on the option to the right "Dropbox API App"
  3. The second option is whether the app will be limited to its "/App/{app name}" folder or have access to Dropbox's root folder. Choose "Yes &mdash; My app only needs access to files it creates." (Choosing "No &mdash; My app needs access to files already on Dropbox." is untested currently)
  4. Lastly give your app a name and click the blue "Create App" button at the bottom right.
2. Now that the app is created you must add the app's details to the [dropbox.yaml](https://github.com/dfrankland/grav-plugin-dropbox/blob/master/dropbox.yaml) file.
  1. Copy the app's key and secret to [dropbox.yaml](https://github.com/dfrankland/grav-plugin-dropbox/blob/master/dropbox.yaml)
  ```
     app:
      key: {your dropbox app key}
      secret: {your dropbox app secret}
  ```
  2. Under "Oauth2" find "Generate access token" and click the "Generate" button. This will create a token which should be copied to the [dropbox.yaml](https://github.com/dfrankland/grav-plugin-dropbox/blob/master/dropbox.yaml)
  ```
     app:
      token: {your dropbox app token}
  ```
3. On your app's console page go to the "Webhooks" section and under "Webhook URIs" enter "https://{yourdomain.tld}/dropbox" (*if you have a self-signed certificate you must use "http://"*) and click the "Add" button. This will verify that the plugin is listening for notifications.
  * If it fails, inspect your Grav installation's settings to double-check everything is setup properly.
  * If needed, use [this tool from Dropbox to test](https://github.com/dropbox/dropbox_hook).
  * If all else fails please [report an issue](https://github.com/dfrankland/grav-plugin-dropbox/issues/new).

### Options

#### Sync Path:
Inside of [dropbox.yaml](https://github.com/dfrankland/grav-plugin-dropbox/blob/master/dropbox.yaml) you may change the folders which Dropbox will sync (changing these values is currently untested).
```
sync:
  remote:
  local: synchronize
```
#### Email alerts:
This feature still needs to be developed.

### How to Use
Please, follow the "Getting Started" instructions above before following these steps.

#### Syncing Dropbox to Grav:
1. In your Dropbox app folder inside `/App/{app name}` add files, folders, or make modifications to them like normal.
2. Watch for Grav to sync the new or modified content to the `grav/user/plugins/dropbox/synchronize` folder.
3. Check the log at `grav/logs/grav.log` for errors. If any exceptions occur please [report an issue](https://github.com/dfrankland/grav-plugin-dropbox/issues/new).

#### Syncing Grav to Dropbox:
1. In the Grav Dropbox plugin folder inside `grav/user/plugins/dropbox/synchronize` add files, folders, or make modifications to them like normal.
2. Make a request to "https://{yourdomain.tld}/dropbox". This can be done manually by using your browser, `curl`ing, or using [the tool provided by Dropbox](https://github.com/dropbox/dropbox_hook). It would be a great idea to set this on a cron schedule to check every couple of minutes!
3. Watch for Dropbox to sync the new or modified content to the app folder inside the `/App/{app name}` folder.
4. Check the log at `grav/logs/grav.log` for errors. If any exceptions occur please [report an issue](https://github.com/dfrankland/grav-plugin-dropbox/issues/new).
