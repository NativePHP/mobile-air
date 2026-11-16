<native:column>
    <native:text>Open {{ $open ? 'yes' : 'no' }} Tab {{ $tab }} Count {{ $count }}</native:text>
    <native:pressable ref="open" @tap="$open = true"><native:text>Open</native:text></native:pressable>
    <native:pressable ref="toggle" @tap="$open = !$open"><native:text>Toggle</native:text></native:pressable>
    <native:pressable ref="tab" @tap="$tab = 'settings'"><native:text>Tab</native:text></native:pressable>
    <native:pressable ref="count" @tap="$count = -2.5"><native:text>Count</native:text></native:pressable>
    <native:pressable ref="clear" @tap="$note = null"><native:text>Clear</native:text></native:pressable>
    <native:pressable ref="admin" @tap="$admin = true"><native:text>Admin</native:text></native:pressable>
    @if ($broken === 'property')
        <native:pressable ref="broken" @tap="$missing = true"><native:text>Broken</native:text></native:pressable>
    @elseif ($broken === 'toggle')
        <native:pressable ref="broken" @tap="$label = !$label"><native:text>Broken</native:text></native:pressable>
    @elseif ($broken === 'method')
        <native:pressable ref="broken" @tap="noSuchMethod"><native:text>Broken</native:text></native:pressable>
    @elseif ($broken === 'expression')
        <native:pressable ref="broken" @tap="$open = foo()"><native:text>Broken</native:text></native:pressable>
    @endif
    <native:bottom-sheet ref="sheet" :visible="$open" @dismiss="$open = false">
        <native:text>Sheet</native:text>
    </native:bottom-sheet>
    <native:saving-child @saved="$saved = true" />
</native:column>
