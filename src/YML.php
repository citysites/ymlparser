<?php

namespace LireinCore\YMLParser;

use LireinCore\YMLParser\Offer\VendorModelOffer;
use LireinCore\YMLParser\Offer\BookOffer;
use LireinCore\YMLParser\Offer\AudioBookOffer;
use LireinCore\YMLParser\Offer\ArtistTitleOffer;
use LireinCore\YMLParser\Offer\MedicineOffer;
use LireinCore\YMLParser\Offer\EventTicketOffer;
use LireinCore\YMLParser\Offer\TourOffer;
use LireinCore\YMLParser\Offer\SimpleOffer;

class YML
{
    /**
     * @var \XMLReader
     */
    protected $XMLReader;

    /**
     * @var string
     */
    protected $uri;

    /**
     * @var string
     */
    protected $schema;

    /**
     * @var array
     */
    protected $pathArr = [];

    /**
     * @var string
     */
    protected $path = '';

    /**
     * @var string
     */
    protected $date;

    /**
     * @var Shop
     */
    protected $shop;

    /** @var bool */
    protected $opened = false;

    /** @var bool */
    protected $exhausted = false;

    /** @var bool */
    protected $previousErrors = false;

    /** @var array */
    protected $containers = [];

    /** @var string[] */
    protected $warnings = [];

    /**
     * YML constructor.
     */
    public function __construct()
    {
        $this->XMLReader = new \XMLReader();
    }

    /**
     * @param string $uri
     * @param string|bool $schema
     * @throws \Exception
     */
    public function parse($uri, $schema = true)
    {
        $this->uri = $uri;
        $this->schema = null;
        if ($schema === true) {
            $this->schema = __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'yml.xsd';
        } elseif (is_string($schema)) {
            $this->schema = $schema;
        }

        try {
            $this->open();
            while ($this->read()) {
                if ($this->path === 'yml_catalog') {
                    $this->date = $this->XMLReader->getAttribute('date');
                    while ($this->read()) {
                        if ($this->path === 'yml_catalog/shop') {
                            $this->shop = $this->parseShop();
                            break;
                        }
                    }
                    break;
                }
            }
        } finally {
            $this->close();
        }
    }

    /**
     * @return string
     */
    public function getDate()
    {
        return $this->date;
    }

    /**
     * @return Shop
     */
    public function getShop()
    {
        return $this->shop;
    }

    /**
     * @return \Generator|SimpleOffer[]|VendorModelOffer[]|BookOffer[]|AudioBookOffer[]|ArtistTitleOffer[]|MedicineOffer[]|EventTicketOffer[]|TourOffer[]
     * @throws \Exception
     */
    public function getOffers()
    {
        try {
            $this->open();
            while ($this->read()) {
                if ($this->path === 'yml_catalog/shop/offers') {
                    while ($this->read()) {
                        if ($this->path === 'yml_catalog/shop/offers/offer') {
                            yield $this->parseOffer();
                        } elseif ($this->path === 'yml_catalog/shop') {
                            break;
                        }
                    }
                    break;
                }
            }
        } finally {
            $this->close();
        }
    }

    /**
     * Предупреждения последнего прохода parse() или getOffers().
     *
     * @return string[]
     */
    public function getWarnings()
    {
        return $this->warnings;
    }

    /**
     * @return Shop
     * @throws \Exception
     */
    protected function parseShop()
    {
        $xml = $this->XMLReader;
        $shop = new Shop();
        $nodes = [];

        while ($this->read()) {
            if ($this->path === 'yml_catalog/shop/offers') {
                $shop->setOffersCount($this->parseOffersCount());
            } elseif ($xml->nodeType === \XMLReader::ELEMENT) {
                $nodes[] = $this->parseNode('yml_catalog/shop');
            } elseif ($this->path === 'yml_catalog') {
                break;
            }
        }

        $shop->fillShop(['name' => 'shop', 'attributes' => [], 'value' => null, 'nodes' => $nodes]);

        return $shop;
    }

    /**
     * @return SimpleOffer|VendorModelOffer|BookOffer|AudioBookOffer|ArtistTitleOffer|MedicineOffer|EventTicketOffer|TourOffer
     * @throws \Exception
     */
    protected function parseOffer()
    {
        $offerNode = $this->parseNode('yml_catalog/shop/offers');

        $type = isset($offerNode['attributes']['type']) ? $offerNode['attributes']['type'] : null;
        return $this->createOffer($type)->fillOffer($offerNode);

    }

    /**
     * @param string $basePath
     * @return array
     * @throws \Exception
     */
    protected function parseNode($basePath)
    {
        $xml = $this->XMLReader;
        $name = $xml->name;
        $path = $basePath . '/' . $name;
        $value = '';
        $nodes = [];
        $isEmpty = $xml->isEmptyElement;

        $attributes = $this->parseAttributes();

        if (!$isEmpty) {
            while ($this->read()) {
                if ($xml->nodeType === \XMLReader::ELEMENT) {
                    $nodes[] = $this->parseNode($path);
                } elseif (($xml->nodeType === \XMLReader::TEXT || $xml->nodeType === \XMLReader::CDATA) && $xml->hasValue) {
                    $value .= $xml->value;
                } elseif ($this->path === $basePath) {
                    break;
                }
            }
        }
        $trimmedValue = trim($value);
        $value = '' !== $trimmedValue ? $trimmedValue : null;

        return ['name' => $name, 'attributes' => $attributes, 'value' => $value, 'nodes' => $nodes];
    }

    /**
     * @return array
     */
    protected function parseAttributes()
    {
        $xml = $this->XMLReader;
        $attributes = [];

        if ($xml->hasAttributes) {
            while ($xml->moveToNextAttribute()) {
                $attributes[$xml->name] = $xml->value;
            }
        }

        return $attributes;
    }

    /**
     * @return int
     * @throws \Exception
     */
    protected function parseOffersCount()
    {
        $count = 0;
        while ($this->read()) {
            $xml = $this->XMLReader;
            if (\XMLReader::ELEMENT === $xml->nodeType && 3 === $xml->depth && 'offer' === $xml->name) {
                $count++;
            } elseif (\XMLReader::END_ELEMENT === $xml->nodeType && 2 === $xml->depth && 'offers' === $xml->name) {
                break;
            }
        }
        return $count;
    }

    /**
     * @throws \RuntimeException
     */
    protected function open()
    {
        $this->previousErrors = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $this->containers = ['yml_catalog' => 0, 'shop' => 0, 'offers' => 0];
        $this->exhausted = false;
        $this->warnings = [];
        try {
            if (!$this->XMLReader->open((string)$this->uri)) {
                throw new \RuntimeException('Cannot open YML document');
            }
            if (!empty($this->schema) && !$this->XMLReader->setSchema($this->schema)) {
                throw new \RuntimeException('Cannot open YML schema');
            }
            $this->opened = true;
        } finally {
            if (!$this->opened) {
                $this->XMLReader->close();
                libxml_clear_errors();
                libxml_use_internal_errors($this->previousErrors);
            }
        }
    }

    protected function close()
    {
        if (!$this->opened) {
            return;
        }
        try {
            // Штатный проход останавливается после shop/offers; проверяем остаток документа до EOF.
            while ($this->read()) {
            }
            $errors = libxml_get_errors();
            if (
                [1, 1, 1] !== array_values($this->containers)
                || array_filter($errors, function ($error) {
                    return LIBXML_ERR_FATAL === $error->level;
                })
            ) {
                throw new \RuntimeException('Invalid or incomplete YML document');
            }
            if ([] !== $errors || ($this->schema && !$this->XMLReader->isValid())) {
                // Семантические ошибки схемы не превращаются в запрет чтения целого файла.
                $this->warnings[] = 'YML schema validation warning';
            }
        } finally {
            $this->pathArr = [];
            $this->path = '';
            $this->XMLReader->close();
            $this->opened = false;
            libxml_clear_errors();
            libxml_use_internal_errors($this->previousErrors);
        }
    }

    /**
     * @return bool
     * @throws \Exception
     */
    protected function read()
    {
        if ($this->exhausted) {
            return false;
        }
        $xml = $this->XMLReader;

        if ($xml->read()) {
            if ($xml->nodeType === \XMLReader::ELEMENT && !$xml->isEmptyElement) {
                $this->pathArr[] = $xml->name;
                $this->path = implode('/', $this->pathArr);
            } elseif ($xml->nodeType === \XMLReader::END_ELEMENT) {
                array_pop($this->pathArr);
                $this->path = implode('/', $this->pathArr);
            }
            if (\XMLReader::ELEMENT === $xml->nodeType) {
                $names = ['yml_catalog', 'shop', 'offers'];
                $expected = isset($names[$xml->depth]) ? $names[$xml->depth] : null;
                if ($expected === $xml->name) {
                    $this->containers[$expected]++;
                }
            }
            return true;
        }

        // Повторный read после ошибки libxml может вернуть старый узел и зациклить парсер.
        $this->exhausted = true;
        return false;
    }

    /**
     * @param $type
     * @return SimpleOffer|VendorModelOffer|BookOffer|AudioBookOffer|ArtistTitleOffer|MedicineOffer|EventTicketOffer|TourOffer
     */
    protected function createOffer($type)
    {
        switch ($type) {
            case 'vendor.model':
                return new VendorModelOffer();
            case 'book':
                return new BookOffer();
            case 'audiobook':
                return new AudioBookOffer();
            case 'artist.title':
                return new ArtistTitleOffer();
            case 'medicine':
                return new MedicineOffer();
            case 'event-ticket':
                return new EventTicketOffer();
            case 'tour':
                return new TourOffer();
            default:
                return new SimpleOffer();
        }
    }
}
