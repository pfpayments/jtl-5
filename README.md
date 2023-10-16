

PostFinanceCheckout Payment for JTL 5.2
=============================

The PostFinanceCheckout Payment plugin wraps around the PostFinanceCheckout API. This library facilitates your interaction with various services such as transactions.

## Requirements

- PHP 8.1
- JTL 5.2

## Installation

**Please install it manually**

### Manual Installation


1. Alternatively you can download the package in its entirety. The [Releases](../../releases) page lists all stable versions.

2. Uncompress the zip file you download and rename it to jtl_postfinancecheckout

3. Include it to your JTL shop plugins folder

4. Run the install command
```bash
# Please go to /plugins/jtl_postfinancecheckout and run the command
composer install
```

5. Login to JTL 5 shop backend > Plug-in manager, select the plugin and click Install

6. After installation click on Settings "gear" icon

7. Enter correct data from PostFinanceCheckout API and click Save. Payment methods will be synchronised and enabled


## Usage
The library needs to be configured with your account's space id, user id, and application key which are available in your PostFinanceCheckout
account dashboard.

## Documentation

[Documentation](https://plugin-documentation.postfinance-checkout.ch/pfpayments/jtl-5/1.0.3/docs/en/documentation.html)

## License

Please see the [license file](https://github.com/pfpayments/jtl-5/blob/master/LICENSE.txt) for more information.
