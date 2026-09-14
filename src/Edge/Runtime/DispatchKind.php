<?php

namespace Native\Mobile\Edge\Runtime;

enum DispatchKind: string
{
    case Interaction = 'interaction';
    case Native = 'native';
}
