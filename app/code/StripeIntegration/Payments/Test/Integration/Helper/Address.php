<?php

namespace StripeIntegration\Payments\Test\Integration\Helper;

class Address
{
    private $regionCollectionFactory;
    private $regionFactory;

    public function __construct(
        \Magento\Directory\Model\RegionFactory $regionFactory,
        \Magento\Directory\Model\ResourceModel\Region\CollectionFactory $regionCollectionFactory
    )
    {
        $this->regionFactory = $regionFactory;
        $this->regionCollectionFactory = $regionCollectionFactory;
    }

    public function getMagentoFormat($identifier)
    {
        switch ($identifier)
        {
            case 'NewYork':
                return [
                    'telephone' => "917-535-4022",
                    'postcode' => "10013",
                    'country_id' => 'US',
                    'region_id' => $this->getRegionId("US", "NY"), // 43 = 8.375%
                    'city' => 'New York',
                    'street' => ['1255 Duncan Avenue'],
                    'lastname' => 'Jerry',
                    'firstname' => 'Flint',
                    'email' => 'flint@example.com',
                ];
            case 'California':
                return [
                    'telephone' => "626-945-7637",
                    'postcode' => "91752",
                    'country_id' => 'US',
                    'region_id' => $this->getRegionId("US", "CA"), // 12 = 8.25%
                    'city' => 'Mira Loma',
                    'street' => ['2974 Providence Lane'],
                    'lastname' => 'Strother',
                    'firstname' => 'Joyce',
                    'email' => 'joyce@example.com',
                ];
            case 'Greece':
                return [
                    'telephone' => "21 0720 8100",
                    'postcode' => "115 28",
                    'country_id' => 'GR',
                    'region_id' => $this->getRegionId("GR", "GR-I"),
                    'city' => 'Athina',
                    'street' => ['Papadiamantopoulou 20'],
                    'lastname' => 'Γρηγοριάδης',
                    'firstname' => 'Εφραίμ',
                    'email' => 'efraim@example.com',
                ];
            case 'Mexico':
                return [
                    'telephone' => "771.715-2115",
                    'postcode' => "42000",
                    'country_id' => 'MX',
                    'region_id' => $this->getRegionId("MX", "PUE"), // Puebla
                    'city' => 'HIDALGO',
                    'street' => ['GUERRERO NO. 521', 'PACHUCA DE SOTO CENTRO'],
                    'lastname' => 'Hopi',
                    'firstname' => 'Huyana',
                    'email' => 'huyana@example.com',
                ];
            case 'Michigan':
                return [
                    'telephone' => "701-270-0720",
                    'postcode' => "58259",
                    'country_id' => 'US',
                    'region_id' => $this->getRegionId("US", "MI"), // 33 = 8.25%
                    'city' => 'Michigan',
                    'street' => ['3510 Catherine Drive'],
                    'lastname' => 'Cook',
                    'firstname' => 'Crystal',
                    'email' => 'crystal@example.com',
                ];
            case 'SofortGermanySuccess':
                return [
                    'telephone' => "030 63 38673",
                    'postcode' => "13469",
                    'country_id' => 'DE',
                    'region_id' => $this->getRegionId("DE", "BER"),
                    'city' => 'Berlin Lübars',
                    'street' => ['Brandenburgische Straße 41'],
                    'lastname' => 'Osterhagen',
                    'firstname' => 'Mario',
                    'email' => 'generatedSepaDebitIntentsSucceedGermany@example.com',
                ];
            case 'Berlin':
                return [
                    'telephone' => "030 63 38673",
                    'postcode' => "13469",
                    'country_id' => 'DE',
                    'region_id' => $this->getRegionId("DE", "BER"),
                    'city' => 'Berlin Lübars',
                    'street' => ['Brandenburgische Straße 41'],
                    'lastname' => 'Osterhagen',
                    'firstname' => 'Mario',
                    'email' => 'osterhagen@example.com',
                ];
            case 'Belgium':
                return [
                    'telephone' => "0490 83 32254",
                    'postcode' => "8510",
                    'country_id' => 'BE',
                    'region_id' => null,
                    'city' => 'Bellegem',
                    'street' => ['Eikstraat 388'],
                    'lastname' => 'Eelman',
                    'firstname' => 'Arjuna',
                    'email' => 'eelman@example.com',
                ];
            case 'London':
                return [
                    'telephone' => "078 7218 3826",
                    'postcode' => "SW1Y 5JH",
                    'country_id' => 'GB',
                    'region_id' => null,
                    'city' => 'London',
                    'street' => ['44 Crown Street'],
                    'lastname' => 'Parker',
                    'firstname' => 'Harry',
                    'email' => 'parker@example.com'
                ];
            case 'Malaysia':
                return [
                    'telephone' => "607-2376867",
                    'postcode' => "81200",
                    'country_id' => 'MY',
                    'region_id' => null,
                    'city' => 'Johor Bahru',
                    'street' => ['101A Jalan Persisiran Perling Taman'],
                    'lastname' => 'Kembang',
                    'firstname' => 'Putri',
                    'email' => 'kembang@example.com'
                ];
            case 'Brazil':
                return [
                    'telephone' => "(11) 5456-7271",
                    'postcode' => "09051-020",
                    'country_id' => 'BR',
                    'region_id' => $this->getRegionId("BR", "SP"), // São Paulo
                    'city' => 'Santo André',
                    'street' => ['Praça Cândido Portinari 1129'],
                    'lastname' => 'Pinto',
                    'firstname' => 'Leila',
                    'email' => 'pinto@example.com'
                ];
            case 'Canada':
                return [
                    'telephone' => "250-384-2275",
                    'postcode' => "V8W 2H9",
                    'country_id' => 'CA',
                    'region_id' => $this->getRegionId("CA", "BC"), // British Columbia
                    'city' => 'Victoria',
                    'street' => ['2181 Blanshard'],
                    'lastname' => 'Hamon',
                    'firstname' => 'Fawn',
                    'email' => 'hamon@example.com'
                ];
            case 'Australia':
                return [
                    'telephone' => "(07) 4916 6836",
                    'postcode' => "4680",
                    'country_id' => 'AU',
                    'region_id' => $this->getRegionId("AU", "QLD"), // Queensland
                    'city' => 'O\'CONNELL',
                    'street' => ['66 Ronald Crescent'],
                    'lastname' => 'Kidman',
                    'firstname' => 'Declan',
                    'email' => 'declan@example.com'
                ];
            case "Tokyo":
                return [
                    'telephone' => "+8153-632-1172",
                    'postcode' => null,
                    'country_id' => 'JP',
                    'region_id' => null,
                    'city' => 'Toshima-ku',
                    'street' => ['21-21, Higashi Ikebukuro 3-chome'],
                    'lastname' => 'Shimei',
                    'firstname' => 'Nihonjin',
                    'email' => 'shimei@example.com'
                ];
            default:
                throw new \Exception("No such address $identifier");
        }
    }

    public function getRegionId($countryCode, $regionCode)
    {
        $collection = $this->regionCollectionFactory->create()
            ->addFieldToSelect('*')
            ->addFieldToFilter('country_id', ['eq' => $countryCode])
            ->addFieldToFilter('code', ['eq' => $regionCode]);

        foreach ($collection as $region)
            return $region->getRegionId();

        return null;
    }

    public function getStripeFormat($identifier)
    {
        $address = $this->getMagentoFormat($identifier);

        if ($address['region_id'])
        {
            $region = $this->regionFactory->create()->load($address['region_id']);
            $state = $region->getName();
        }
        else
        {
            $state = null;
        }

        $params = [
            'address' => [
                'city' => $address['city'],
                'country' => $address['country_id'],
                'line1' => $address['street'][0],
                'postal_code' => $address['postcode'],
                'state' => $state
            ],
            'email' => $address['email'],
            'name' => $address['firstname'] . " " . $address['lastname'],
            'phone' => $address['telephone']
        ];

        switch ($identifier)
        {
            case "SofortGermanySuccess":
                $params["name"] = "succeeding_charge";
                break;
        }

        return $params;
    }

    public function getStripeShippingFormat($identifier)
    {
        $address = $this->getStripeFormat($identifier);
        unset($address["email"]);
        return $address;
    }

    public function getPRAPIFormat($identifier)
    {
        $address = $this->getMagentoFormat($identifier);
        $address["country"] = $address["country_id"];
        unset($address["country_id"]);

        if ($address['region_id'])
        {
            $region = $this->regionFactory->create()->load($address['region_id']);
            $address["region"] = $region->getName();
        }
        else
        {
            $address["region"] = null;
        }
        unset($address["region_id"]);

        $address["postalCode"] = $address["postcode"];
        unset($address["postcode"]);

        $address["recipient"] = $address["firstname"] . " " . $address["lastname"];
        unset($address["firstname"]);
        unset($address["lastname"]);

        $address["phone"] = $address["telephone"];
        unset($address["telephone"]);

        $address["addressLine"] = $address["street"];
        unset($address["street"]);

        $address["sortingCode"] = "";
        $address["dependentLocality"] = "";
        $address["organization"] = "";

        return $address;
    }
}
