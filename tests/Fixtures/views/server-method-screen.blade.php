<native:column fill>
    <native:text>Range {{ $range }}</native:text>
    <native:text>Total {{ $total }}</native:text>
    <native:text>Note {{ $note }}</native:text>
    @if ($loading)
        <native:text>Loading…</native:text>
    @endif
    @if ($error !== '')
        <native:text>{{ $error }}</native:text>
    @endif
    @server('sales-summary')
        <native:text>Loading sales…</native:text>
    @endserver
    <native:text @tap="refreshChart('30d')" ref="refresh">Refresh</native:text>
</native:column>
