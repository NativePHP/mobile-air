<native:column>
    <native:text>Count: {{ $count }}</native:text>
    <native:pressable ref="bump" @tap="bump">
        <native:text>Bump</native:text>
    </native:pressable>
    @server('picks')
        <native:text>Loading picks…</native:text>
    @endserver
    <native:action-pipeline-child word="hi" @child-said="markSaved('tag')" />
</native:column>
