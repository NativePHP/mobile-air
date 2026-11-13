<?php

namespace Tests\Fixtures\Edge;

use Native\Mobile\Attributes\ServerAddress;

/** FragmentSourceScreen under an address of its own choosing. */
#[ServerAddress('sales')]
class NamedRegionScreen extends FragmentSourceScreen {}
