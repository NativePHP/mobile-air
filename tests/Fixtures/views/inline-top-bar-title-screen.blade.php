<native:top-bar title="Inline Title" display-mode="large">
    <native:top-bar-title>
        <native:pressable @tap="brandTapped">
            <native:text>Brand Lockup</native:text>
        </native:pressable>
    </native:top-bar-title>
    <native:top-bar-action id="save" icon="check" label="Save" />
</native:top-bar>
<native:column>
    <native:text>Top bar body</native:text>
</native:column>
