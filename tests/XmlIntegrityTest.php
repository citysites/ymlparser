<?php

use LireinCore\YMLParser\YML;

spl_autoload_register(function ($class) {
    $prefix = 'LireinCore\\YMLParser\\';
    if (0 === strpos($class, $prefix)) {
        require dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    }
});

function checkIntegrity($condition, $message)
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function rejectDocument(YML $parser, $file, $schema)
{
    try {
        $parser->parse($file, $schema);
        iterator_to_array($parser->getOffers());
    } catch (RuntimeException $error) {
        return;
    }
    throw new RuntimeException('Damaged document accepted');
}

$prefix = '<yml_catalog date="2026-11-26 12:00"><shop><name>Test</name><company>Test</company>'
    . '<url>https://example.test</url><currencies><currency id="UAH" rate="1"/></currencies>'
    . '<categories><category id="1">Test</category></categories><offers>';
$offer = '<offer id="001" available="true"><name>Product</name><price>100</price>'
    . '<currencyId>UAH</currencyId><categoryId>1</categoryId>'
    . '<picture>https://example.test/product.jpg</picture></offer>';
$suffix = '</offers></shop></yml_catalog>';
$valid = $prefix . $offer . $suffix;
$file = tempnam(sys_get_temp_dir(), 'yml-integrity-');
$previousErrors = libxml_use_internal_errors(true);
$passed = 0;
try {
    foreach ([false, true, dirname(__DIR__) . '/yml-catalog-extended.xsd'] as $schema) {
        $parser = new YML();
        file_put_contents($file, $valid);
        $parser->parse($file, $schema);
        $expected = iterator_to_array($parser->getOffers());
        checkIntegrity(1 === count($expected), 'Product count changed');
        checkIntegrity('001' === $expected[0]->getId(), 'String product ID changed');
        checkIntegrity([] === $parser->getWarnings(), 'Valid document produced warnings');
        $passed++;

        foreach ([$prefix . $suffix, str_replace('<offers>', '<offers/>', $prefix) . '</shop></yml_catalog>'] as $empty) {
            file_put_contents($file, $empty);
            $parser->parse($file, $schema);
            checkIntegrity(0 === $parser->getShop()->getOffersCount(), 'Empty catalog counted products');
            checkIntegrity([] === iterator_to_array($parser->getOffers()), 'Empty catalog did not return []');
            $passed++;
        }

        foreach ([
            str_replace('<offers>', '<auxiliary><offer id="aux"/></auxiliary><offers>', $valid),
            str_replace('<offers>', '<offers><wrapper><offer id="nested"/></wrapper>', $valid),
            str_replace('<offers>', '<offers><other:offer xmlns:other="urn:test" id="other"/>', $valid),
        ] as $auxiliary) {
            file_put_contents($file, $auxiliary);
            $parser->parse($file, $schema);
            $actual = iterator_to_array($parser->getOffers());
            checkIntegrity(1 === count($actual), 'Auxiliary product was imported');
            checkIntegrity('001' === $actual[0]->getId(), 'Actual product was skipped');
            checkIntegrity(1 === $parser->getShop()->getOffersCount(), 'Auxiliary product affected count');
            $passed++;
        }

        foreach ([
            '', '<wrong/>', '<yml_catalog><offers/></yml_catalog>',
            '<yml_catalog><shop/></yml_catalog>',
            $prefix . $offer, $prefix . '<offer id="unfinished',
            $valid . '<!--' . str_repeat('padding', 2000) . '--><broken',
            $prefix . str_repeat($offer, 10000),
        ] as $damaged) {
            file_put_contents($file, $damaged);
            rejectDocument($parser, $file, $schema);
            checkIntegrity(true === libxml_use_internal_errors(), 'libxml mode was not restored');
            file_put_contents($file, $valid);
            $parser->parse($file, $schema);
            checkIntegrity(1 === count(iterator_to_array($parser->getOffers())), 'Parser cannot recover after failure');
            $passed++;
        }

        file_put_contents($file, str_replace('<name>Test</name>',
            '<name>Test</name><schema_extension>unknown</schema_extension>', $valid));
        $parser->parse($file, $schema);
        checkIntegrity(1 === count(iterator_to_array($parser->getOffers())), 'Schema warning blocked product');
        checkIntegrity((false !== $schema) === ([] !== $parser->getWarnings()), 'Schema warning missing');
        $parser->parse($file, false);
        checkIntegrity(1 === count(iterator_to_array($parser->getOffers())), 'Disabling schema changed products');
        checkIntegrity([] === $parser->getWarnings(), 'Previous schema remained active');
        $passed++;

        file_put_contents($file, $valid);
        $parser->parse($file, $schema);
        file_put_contents($file, $prefix . '<offer id="broken');
        try {
            iterator_to_array($parser->getOffers());
            throw new LogicException('Second pass accepted damaged document');
        } catch (RuntimeException $error) {
            $passed++;
        }

        file_put_contents($file, $prefix . str_repeat($offer, 10000) . $suffix);
        $parser->parse($file, $schema);
        checkIntegrity(10000 === $parser->getShop()->getOffersCount(), 'Large catalog count changed');
        $count = 0;
        foreach ($parser->getOffers() as $product) {
            checkIntegrity('001' === $product->getId(), 'Large catalog product ID changed');
            $count++;
        }
        checkIntegrity(10000 === $count, 'Large catalog was not fully read');
        $passed++;
    }

    libxml_use_internal_errors(false);
    file_put_contents($file, $valid);
    $parser->parse($file, false);
    iterator_to_array($parser->getOffers());
    checkIntegrity(false === libxml_use_internal_errors(), 'Disabled libxml mode was not restored');
    $passed++;

    echo $passed . " integrity scenarios passed\n";
} finally {
    unlink($file);
    libxml_clear_errors();
    libxml_use_internal_errors($previousErrors);
}
