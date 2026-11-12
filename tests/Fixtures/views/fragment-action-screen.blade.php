<native:column fill>
    <native:text>Range {{ $range }}</native:text>
    @server('picks')
        <native:pressable @tap="retry">
            <native:text>Retry picks</native:text>
        </native:pressable>
    @endserver
    @server('extras')
        <native:text>Loading extras…</native:text>
    @endserver
    @if ($withChild)
        <native:fragment-action-child />
    @endif
    <native:pressable @tap="unmarked">
        <native:text>Screen button</native:text>
    </native:pressable>
</native:column>
