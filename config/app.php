<?php

return [

    /*
     * Fiscal timestamps are signed without a timezone (PORTING.md §5), so the app clock is
     * pinned rather than inherited from the host.
     */
    'timezone' => 'Europe/Lisbon',

];
