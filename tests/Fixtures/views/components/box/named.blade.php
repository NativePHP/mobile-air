<native:row>
    <native:text>Title</native:text>
    <native:stack>{{ $actions ?? '' }}</native:stack>
</native:row>
<native:column>{{ $slot }}</native:column>
