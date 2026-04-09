# signotec signoSign/Universal for Nextcloud

Integrates **signotec signoSign/Universal** into Nextcloud to sign
documents locally or send them into remote signature workflows via
email.

## Features

-   Start signing workflows directly from Nextcloud
-   Support for local signing with signotec signoSign/Universal
-   Support for remote signing workflows via email
-   Integration into the Nextcloud administration settings
-   Webhook-based processing for signing results
-   Automatic update of documents after completed signing workflows

## Requirements

-   Nextcloud **32**
-   A working **signotec signoSign/Universal** installation
-   Valid access credentials for signotec signoSign/Universal
-   Network connectivity between Nextcloud and the signotec environment
-   Webhook endpoint reachable from the signotec environment if remote
    workflows are used

## Installation

### From source

Clone or copy the app into your Nextcloud app directory, for example:

cd /var/www/html/apps-extra git clone `<repository-url>`{=html}
signotecsignosignuniversal

Then install dependencies in the app folder:

cd /var/www/html/apps-extra/signotecsignosignuniversal composer install

Enable the app:

php occ app:enable signotecsignosignuniversal

## Configuration

After enabling the app, open the Nextcloud administration settings and
configure the signotec integration.

Typical configuration values include:

-   signoSign/Universal server URL
-   Username
-   Password
-   Signature field configuration

## Usage

After configuration, users can start signing workflows from within
Nextcloud.

## Testing

Run tests:

./vendor/bin/phpunit -c phpunit.xml

## License

AGPL-3.0-or-later
