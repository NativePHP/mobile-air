<native:column fill>
    @server('stats')
        <native:text>Loading stats…</native:text>
    @endserver
    @server('feed')
        <native:text>Loading feed…</native:text>
    @endserver
    @if ($withChild)
        <native:fragment-source-child label="orders" />
    @endif
    <native:text @tap="retry" ref="retry">Retry</native:text>
</native:column>
