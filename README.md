# Joomla AesirX SSO login plugin

This plugin is for AesirX SSO to process a login with the Joomla frontoffice and backoffice, based on the AesirX account.
You will need to set up and install Aesir SSO from here [https://github.com/aesirxio/sso].

Also this is a Joomla 4 plugin and will not work in the 3.XX versions or any older ones.

To install this you will need to clone this repo locally with command:

`git clone https://github.com/aesirxio/joomla-sso-plugin.git`

## PHP set up

After that you can run the next commands.

`npm i` - initialize libraries

`npm run build` - for building Joomla zip installer (PHP 7.2 or higher)

`npm run watch` - for watching changes in the JS when developing

## Docker set up

### Linux

Alternatively can be used docker-compose with npm and php included, see available commands in `Makefile`:
_Before build docker container please make sure you set correct USER_ID and GROUP_ID in .env file_

`make init` - initialize libraries

`make build` - for building Joomla zip installer (PHP 7.2 or higher)

`make watch` - for watching changes in the JS when developing

### Windows

If you don't have Makefile set uo on Windows you can use direct docker commands.

`docker-compose run php-npm npm i` - initialize libraries

`docker-compose run php-npm npm run build` - for building Joomla zip installer (PHP 7.2 or higher)

`docker-compose run php-npm npm run watch` - for watching changes in the JS when developing

## Installing and Set up

After running the build the install package will be created in the `dist` folder.

### Configuration

The first options are for which logins do you want to use this plugin and you have wallets set up:
- `Concordium` - Concordium wallet login
- `Metamask` - Metamask wallet login
- `Regular Login` - regular login to your site

The `Endpoint` field is always the [https://api.aesirx.io].

The `Client id` and `Client secret` are aveiable after free registration on the [https://partners.aesirx.io] site 
under Licenses tab.
You will need to Edit the Aesir SSO licences with `Domain` and `Test domian` to have the `SSO CLIENT ID` and
`SSO CLIENT SECRECT` display on the Licenses tab page.
