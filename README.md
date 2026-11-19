# YML (Yandex Market Language) parser

This is the [CitySites fork](https://github.com/citysites/ymlparser) of [lireincore/ymlparser](https://github.com/lireincore/ymlparser).

## About

A streaming parser for [YML (Yandex Market Language)](https://yandex.ru/support/marketplace/ru/assortment/auto/yml.html) that uses
`XMLReader` to validate and process large files. It supports [Prom.ua product feeds](https://support.prom.ua/hc/uk/articles/360004963538),
including [Horoshop exports for Prom.ua](https://help.horoshop.ua/uk/articles/4301171-вивантаження-товарів-для-prom-ua).

The fork preserves the value `0` in XML nodes and accepts `True` and `False` when validating
the `store`, `pickup`, and `delivery` fields.

## Install

Add the fork as a repository in `composer.json` and require its main branch.

```json
{
    "repositories": [
        {
            "type": "git",
            "url": "https://github.com/citysites/ymlparser"
        }
    ],
    "require": {
        "lireincore/ymlparser": "dev-master"
    }
}
```

## Usage

```php
use LireinCore\YMLParser\YML;

$yml = new YML();
try {
    $yml->parse($filepath);
    $date = $yml->getDate();
    $shop = $yml->getShop();
    if ($shop->isValid()) {
        $offersCount = $shop->getOffersCount();
        $shopData = $shop->getData();
        //...
        foreach ($yml->getOffers() as $offer) {
            if ($offer->isValid()) {
                $offerCategoryHierarchy = $shop->getCategoryHierarchy($offer->getCategoryId());
                $offerData = $offer->getData();
                //...
            } else {
                var_dump($offer->getErrors());
                //...
            }
        }
    } else {
        var_dump($shop->getErrors());
        //...
    }
} catch (\Exception $e) {
    echo $e->getMessage();
    //...
}
```

### Choosing a schema

- [`yml.xsd`](yml.xsd) is used by `parse($filepath)`. It validates the shop, currency, and category structure.
- [`yml-catalog-extended.xsd`](yml-catalog-extended.xsd) checks each `<offer>` against an explicit list of allowed elements and attributes.
  For Prom.ua product feeds, pass the path to this schema as the second argument.

Both schemas accept feeds where the shop omits `name`, `company`, or `url`.

With Composer 2, use the installed package path to select the extended schema.

```php
use Composer\InstalledVersions;
use LireinCore\YMLParser\YML;

$packagePath = InstalledVersions::getInstallPath('lireincore/ymlparser');
$yml = new YML();
$yml->parse($filepath, $packagePath . '/yml-catalog-extended.xsd');
```

### Product availability

| `available` attribute | `getAvailable()` result |
| --- | --- |
| `"true"` or `"склад"` | `true` |
| `"false"` or `""` | `false` |
| Attribute omitted | `null` |

The extended schema accepts `in_stock` as a readiness-to-ship flag. `getAvailable()` uses `available` to determine
availability, including when `in_stock="true"`.

## License

The MIT License (MIT). Please see [License File](LICENSE) for more information.
