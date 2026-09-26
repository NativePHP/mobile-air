<native:bottom-nav>
    <native:bottom-nav-item id="home" label="Home" url="/" icon="home" active />
    <native:bottom-nav-item id="create" label="Create" icon="add" @tap="create"
                            :raised="$canCreate" raised-size="56" raised-color="teal-500"
                            raised-ring-color="#FFFFFFDB" raised-ring-width="4"
                            raised-dock-with-keyboard="false" />
    <native:bottom-nav-item id="settings" label="Settings" url="/settings" icon="gear" />
</native:bottom-nav>
<native:column>
    <native:text>Raised nav body</native:text>
</native:column>
