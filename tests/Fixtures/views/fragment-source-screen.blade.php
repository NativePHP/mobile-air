<native:column fill>
    <native:text>Range {{ $range }}</native:text>
    @server('sales-summary')
        <native:text>Loading sales…</native:text>
    @endserver
    @if ($withChild)
        <native:fragment-source-child label="orders" />
    @endif
    <native:text @tap="tapped" ref="tap">Taps {{ $taps }}</native:text>
    <native:text @tap="refresh('30d')" ref="refresh">Refresh</native:text>
</native:column>
