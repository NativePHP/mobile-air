# Third-party notices

This package includes work derived from other open-source projects. Their
copyright notices and license terms are reproduced below, as those licenses
require.

## Livewire

Parts of the native component lifecycle are derived from Livewire
(https://github.com/livewire/livewire), specifically:

| File | Derived from |
| --- | --- |
| `src/Edge/ComponentMethodInvoker.php` | `Livewire\ImplicitlyBoundMethod`, and the lifecycle-hook guard in `Livewire\Features\SupportLifecycleHooks\SupportLifecycleHooks::call()` |
| `src/Edge/ComponentState.php` | The property-update hook naming and ordering in `Livewire\Features\SupportLifecycleHooks\SupportLifecycleHooks` |
| `src/Edge/ComponentEvent.php` | `Livewire\Features\SupportEvents\Event` |
| `src/Edge/Exceptions/DirectlyCallingLifecycleHooksNotAllowedException.php` | `Livewire\Exceptions\DirectlyCallingLifecycleMethodsNotAllowedException` |

Behaviour diverges from upstream in places — notably, pure enums are never
treated as implicitly bindable, an invalid backed-enum case raises
`BackedEnumCaseNotFoundException` rather than binding null, and an empty
string binds null for a nullable parameter.

### MIT License

Copyright © Caleb Porzio

Permission is hereby granted, free of charge, to any person obtaining a copy of this software and associated documentation files (the "Software"), to deal in the Software without restriction, including without limitation the rights to use, copy, modify, merge, publish, distribute, sublicense, and/or sell copies of the Software, and to permit persons to whom the Software is furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.
