<?php

// Shared presentation dictionary, used by PHP and Vue. No routing authorization
// is granted by a region label.
return json_decode(file_get_contents(__DIR__.'/../resources/data/countries/order-country-display.json'), true, 512, JSON_THROW_ON_ERROR);
