# Pyxl Core Magento 2 Module
An empty module that provides the Pyxl tab in Stores -> Configuration. To be used for any module providing custom configuration fields under the Pyxl namespace. 

## Getting Started
To install into your existing Magento site run the following two commands. 

    composer config repositories.pyxl-core git https://github.com/thinkpyxl/magento2-Pyxl_Core.git
    composer require pyxl/core:^1.0.0
    bin/magento module:enable Pyxl_Core
    bin/magento setup:upgrade
    bin/magento cache:clean 

## Authors
* Joel Rainwater
