<?php

$objectManager = \Magento\TestFramework\ObjectManager::getInstance();

$repository = $objectManager->create(\Magento\Customer\Api\CustomerRepositoryInterface::class);

try
{
    $customer = $repository->get('customer@example.com');
    return;
}
catch (\Exception $e)
{
    // Customer was not found
    $customer = $objectManager->create(\Magento\Customer\Api\Data\CustomerInterface::class);
}

$customer->setWebsiteId(1)
    // ->setId(1)
    ->setEmail('customer@example.com')
    ->setGroupId(1)
    ->setStoreId(1)
    ->setPrefix('Mr.')
    ->setFirstname('John')
    ->setMiddlename('A')
    ->setLastname('Smith')
    ->setSuffix('Esq.')
    ->setDefaultBilling(1)
    ->setDefaultShipping(1)
    ->setTaxvat('12')
    ->setGender(0);

$customer = $repository->save($customer);

$addressRepository = $objectManager->create(\Magento\Customer\Api\AddressRepositoryInterface::class);
$addressDataFactory = $objectManager->create(\Magento\Customer\Api\Data\AddressInterfaceFactory::class);

$addresses = [
    [
        'telephone' => "917-535-4022",
        'postcode' => "10013",
        'country_id' => 'US',
        'region_id' => 12,
        'city' => 'New York',
        'street' => ['1255 Duncan Avenue'],
        'lastname' => 'Jerry',
        'firstname' => 'Flint',
        'email' => 'jerryflint@example.com',
        'customer_id' => $customer->getId(),
        'is_default_billing' => 1,
        'is_default_shipping' => 1
    ],
    [
        'telephone' => 3234676,
        'postcode' => 47676,
        'country_id' => 'US',
        'city' => 'CityX',
        'street' => ['Black str, 48'],
        'lastname' => 'Smith',
        'firstname' => 'John',
        'email' => 'some_email@mail.com',
        'region_id' => 1,
        'customer_id' => $customer->getId(),
        'is_default_billing' => 0,
        'is_default_shipping' => 0
    ],
    [
        'telephone' => 123123,
        'postcode' => 'ZX0789',
        'country_id' => 'US',
        'city' => 'Ena4ka',
        'street' => ['Black', 'White'],
        'lastname' => 'Doe',
        'firstname' => 'John',
        'email' => 'some_email@mail.com',
        'region_id' => 2,
        'customer_id' => $customer->getId(),
        'is_default_billing' => 0,
        'is_default_shipping' => 0
    ]
];

foreach ($addresses as $addressData)
{
    $address = $addressDataFactory->create();
    $address->setFirstname($addressData['firstname'])
            ->setLastname($addressData['lastname'])
            ->setCountryId($addressData['country_id'])
            ->setRegionId($addressData['region_id'])
            // ->setRegion($addressData)
            ->setCity($addressData['city'])
            ->setPostcode($addressData['postcode'])
            ->setCustomerId($customer->getId())
            ->setStreet($addressData['street'])
            ->setTelephone($addressData['telephone'])
            ->setIsDefaultBilling($addressData['is_default_billing'])
            ->setIsDefaultShipping($addressData['is_default_shipping']);

    $addressRepository->save($address);
}
