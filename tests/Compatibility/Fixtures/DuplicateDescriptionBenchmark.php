<?php

declare(strict_types=1);

benchmark('duplicate lifecycle description', fn (): string => 'first');
benchmark('duplicate lifecycle description', fn (): string => 'second');
