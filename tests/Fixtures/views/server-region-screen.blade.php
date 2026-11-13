<native:column fill>
    <native:text>Dashboard</native:text>
    @server('sales-summary')
        <native:text>Loading sales…</native:text>
    @endserver
    <native:text @tap="refresh" ref="refresh">Refreshed {{ $refreshes }}</native:text>
</native:column>
