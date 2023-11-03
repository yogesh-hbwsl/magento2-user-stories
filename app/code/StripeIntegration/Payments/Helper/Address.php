<?php

namespace StripeIntegration\Payments\Helper;

use Magento\Framework\Exception\LocalizedException;
use StripeIntegration\Payments\Exception\InvalidAddressException;

class Address
{
    private $countryFactory;
    private $directoryHelper;

    public function __construct(
        \Magento\Directory\Model\CountryFactory $countryFactory,
        \Magento\Directory\Helper\Data $directoryHelper
    ) {
        $this->countryFactory = $countryFactory;
        $this->directoryHelper = $directoryHelper;
    }

    public function getStripeAddressFromMagentoAddress($address)
    {
        if (empty($address))
            return null;

        $data = [
            "address" => [
                "line1" => $address->getStreetLine(1),
                "line2" => $address->getStreetLine(2),
                "city" => $address->getCity(),
                "country" => $address->getCountryId(),
                "postal_code" => $address->getPostcode(),
                "state" => $address->getRegion()
            ],
            "name" => $address->getName(),
            "email" => $address->getEmail(),
            "phone" => substr((string)$address->getTelephone(), 0, 20)
        ];

        foreach ($data['address'] as $key => $value) {
            if (empty($data['address'][$key]))
                unset($data['address'][$key]);
        }

        foreach ($data as $key => $value) {
            if (empty($data[$key]))
                unset($data[$key]);
        }

        return $data;
    }

    public function getStripeShippingAddressFromMagentoAddress($address)
    {
        if (empty($address))
            return null;

        $data = [
            "address" => [
                "line1" => $address->getStreetLine(1),
                "line2" => $address->getStreetLine(2),
                "city" => $address->getCity(),
                "country" => $address->getCountryId(),
                "postal_code" => $address->getPostcode(),
                "state" => $address->getRegion()
            ],
            "carrier" => null,
            "name" => $address->getFirstname() . " " . $address->getLastname(),
            "phone" => $address->getTelephone(),
            "tracking_number" => null
        ];

        foreach ($data['address'] as $key => $value) {
            if (empty($data['address'][$key]))
                unset($data['address'][$key]);
        }

        foreach ($data as $key => $value) {
            if (empty($data[$key]))
                unset($data[$key]);
        }

        return $data;
    }

    public function getMagentoAddressFromPRAPIPaymentMethodData($data)
    {
        $nameObject = $this->parseFullName($data['name'], __("billing"));
        $firstName = $nameObject->getFirstname();
        $lastName = $nameObject->getLastname();
        $street = [
            0 => (!empty($data['address']['line1']) ? $data['address']['line1'] : 'Unspecified Street'),
            1 => (!empty($data['address']['line2']) ? $data['address']['line2'] : '')
        ];
        $city = (!empty($data['address']['city']) ? $data['address']['city'] : 'Unspecified City');
        $region = (!empty($data['address']['state']) ? $data['address']['state'] : 'Unspecified Region');
        $postcode = (!empty($data['address']['postal_code']) ? $data['address']['postal_code'] : 'Unspecified Postcode');
        $country = (!empty($data['address']['country']) ? $data['address']['country'] : 'Unspecified Country');

        // Get Region Id
        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $regionId = $this->getRegionIdBy($regionName = $region, $regionCountry = $country);

        return [
            'firstname' => $firstName,
            'lastname' => $lastName,
            'company' => '',
            'email' => $data['email'],
            'street' => $street,
            'city' => $city,
            'region_id' => $regionId,
            'region' => $region,
            'postcode' => $postcode,
            'country_id' => $country,
            'telephone' => $data['phone'],
            'fax' => '',
        ];
    }

    public function getMagentoAddressFromPRAPIResult($address, $addressType)
    {
        if (!is_array($address) || empty($address['country']) || empty($address['country']))
            throw new InvalidAddressException(__("Invalid %1 address.", $addressType));

        if (empty($address['recipient']))
        {
            $firstName = null;
            $lastName = null;
        }
        else
        {
            $nameObject = $this->parseFullName($address['recipient'], $addressType);
            $firstName = $nameObject->getFirstname();
            $lastName = $nameObject->getLastname();
        }

        // Get Region Id
        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();

        if (empty($address['region']))
            $regionId = null;
        else
            $regionId = $this->getRegionIdBy($regionName = $address['region'], $regionCountry = $address['country']);

        if (empty($address['postalCode']))
            $address['postalCode'] = null;

        if (empty($address['phone']))
            $address['phone'] = null;

        return [
            'firstname' => $firstName,
            'lastname' => $lastName,
            'company' => (empty($address['organization']) ? null : $address['organization']),
            'email' => '',
            'street' => (empty($address['addressLine']) ? null : $address['addressLine']),
            'city' => $address['city'],
            'region_id' => $regionId,
            'region' => (empty($address['region']) ? null : $address['region']),
            'postcode' => $address['postalCode'],
            'country_id' => $address['country'],
            'telephone' => $address['phone'],
            'fax' => ''
        ];
    }

    public function getRegionIdBy($regionName, $regionCountry)
    {
        $regions = $this->getRegionsForCountry($regionCountry);

        $regionName = $this->clean($regionName);

        if (isset($regions['byName'][$regionName]))
            return $regions['byName'][$regionName];
        else if (isset($regions['byCode'][$regionName]))
            return $regions['byCode'][$regionName];

        return null;
    }

    public function getRegionsForCountry($countryCode)
    {
        $values = array();

        $country = $this->countryFactory->create()->loadByCode($countryCode);

        if (empty($country))
            return $values;

        $regions = $country->getRegions();

        foreach ($regions as $region)
        {
            $values['byCode'][$this->clean($region->getCode())] = $region->getId();
            $values['byName'][$this->clean($region->getName())] = $region->getId();
        }

        return $values;
    }

    public function clean($str)
    {
        if (empty($str))
            return null;

        return strtolower(trim($str));
    }

    public function parseFullName($name, $nameType)
    {
        if (empty($name) || empty(trim($name)))
            throw new LocalizedException(__("No name specified in your %1 address.", $nameType));

        $name = trim($name);
        $name = preg_replace('!\s+!', ' ', $name); // Replace multiple spaces

        $nameParts = explode(' ', $name);
        $firstName = array_shift($nameParts);

        if (empty($firstName) || count($nameParts) == 0)
            throw new LocalizedException(__("Please specify your full name in your %1 address.", $nameType));

        $lastName = implode(" ", $nameParts);

        // @codingStandardsIgnoreStart
        $return = new \Magento\Framework\DataObject();
        // @codingStandardsIgnoreEnd
        return $return->setFirstname($firstName)
                      ->setLastname($lastName);
    }

    public function convertCamelCaseKeysToSnakeCase(array $elements): array
     {
        $output = [];

        foreach ($elements as $key => $value)
        {
            $newKey = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $key));
            $output[$newKey] = $value;
        }

        return $output;
     }

    public function filterAddressData($data)
    {
        $allowed = ['prefix', 'firstname', 'middlename', 'lastname', 'email', 'suffix', 'company', 'street', 'city', 'country_id', 'region', 'region_id', 'postcode', 'telephone', 'fax', 'vat_id'];
        $remove = [];

        $data = $this->convertCamelCaseKeysToSnakeCase($data);

        foreach ($data as $key => $value)
        {
            if (!in_array($key, $allowed))
                $remove[] = $key;
        }

        foreach ($remove as $key)
        {
            unset($data[$key]);
        }

        return $data;
    }

    public function isRegionRequired($countryCode)
    {
        return $this->directoryHelper->isRegionRequired($countryCode);
    }

    public function getShippingAddressFromOrder($order)
    {
        if (empty($order) || $order->getIsVirtual())
            return null;

        $address = $order->getShippingAddress();

        if (empty($address))
            return null;

        if (empty($address->getFirstname()))
            return null;

        return $this->getStripeShippingAddressFromMagentoAddress($address);
    }
}
