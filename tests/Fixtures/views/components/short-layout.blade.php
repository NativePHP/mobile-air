@props(['title' => ''])

<top-bar :title="$title">
    {{ $actions ?? '' }}
</top-bar>

{{ $slot }}

<bottom-nav>
    <bottom-nav-item id="chats" label="Chats" url="/chats" icon="chat" />
    <bottom-nav-item id="friends" label="Friends" url="/friends" icon="group" />
</bottom-nav>
