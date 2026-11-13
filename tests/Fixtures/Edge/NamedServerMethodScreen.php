<?php

namespace Tests\Fixtures\Edge;

use Native\Mobile\Attributes\ServerAddress;

/** ServerMethodScreen under an address of its own choosing. */
#[ServerAddress('reports.chart')]
class NamedServerMethodScreen extends ServerMethodScreen {}
