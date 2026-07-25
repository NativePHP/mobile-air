<native:top-bar title="Icons">
    <native:top-bar-action id="star" icon="star" label="Star"
                           :ios-icon="Tests\Fixtures\Edge\FixtureIosIcon::Star"
                           :android-icon="Tests\Fixtures\Edge\FixtureAndroidIcon::StarOutline"
                           @tap="noop" />
</native:top-bar>
<native:bottom-nav>
    <native:bottom-nav-item id="inbox" label="Inbox" icon="inbox" url="/"
                            :ios-icon="Tests\Fixtures\Edge\FixtureIosIcon::Tray"
                            :android-icon="Tests\Fixtures\Edge\FixtureAndroidIcon::Inbox" />
</native:bottom-nav>
<native:column>
    <native:text>Body</native:text>
</native:column>
<native:fab :ios-icon="Tests\Fixtures\Edge\FixtureIosIcon::Plus"
            :android-icon="Tests\Fixtures\Edge\FixtureAndroidIcon::Add"
            @tap="noop" />
