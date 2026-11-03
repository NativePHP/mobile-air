<native:top-bar title="Notes">
    <native:top-bar-action id="more" icon="ellipsis" a11y-label="More">
        <native:top-bar-action row>
            <native:top-bar-action id="scan" icon="doc.viewfinder" label="Scan" />
            <native:top-bar-action id="pin" icon="pin" label="Pin" />
        </native:top-bar-action>
        <native:top-bar-action section label="View">
            <native:top-bar-action id="view_as" icon="square.grid.2x2" label="View as" subtitle="Gallery">
                <native:top-bar-action id="gallery" label="Gallery" :checked="$display === 'gallery'" @tap="viewAs('gallery')" />
                <native:top-bar-action id="list" label="List" :checked="$display === 'list'" @tap="viewAs('list')" />
            </native:top-bar-action>
        </native:top-bar-action>
        <native:top-bar-action section>
            <native:top-bar-action id="delete" icon="trash" label="Delete" destructive @tap="delete" />
        </native:top-bar-action>
    </native:top-bar-action>
</native:top-bar>
<native:column>
    <native:text>Inline menu body</native:text>
</native:column>
