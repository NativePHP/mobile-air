@props(['title' => ''])

<native:top-bar :title="$title">
    {{ $actions ?? '' }}
</native:top-bar>

{{ $slot }}

<native:bottom-nav>
    <native:bottom-nav-item id="chats" label="Chats" url="/chats" icon="chat" />
    <native:bottom-nav-item id="friends" label="Friends" url="/friends" icon="group" />
</native:bottom-nav>
