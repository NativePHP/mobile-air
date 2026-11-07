<native:column>
    <native:text>Count: {{ $count }}</native:text>
    <native:pressable ref="bump" @tap="bump">
        <native:text>Bump</native:text>
    </native:pressable>
    <native:action-pipeline-child word="hi" @child-said="markSaved('tag')" />
</native:column>
