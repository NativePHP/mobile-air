<?php

namespace Native\Mobile\Edge\Exceptions;

use Illuminate\Validation\ValidationException;

/**
 * A ValidationException whose messages validate()/validateOnly() already
 * recorded on the throwing component's error bag. runGuarded() folds the
 * messages of any OTHER ValidationException (author-thrown withMessages,
 * a bare validator) into the catching component's bag — this subclass is
 * how it knows not to double-record, without any shared state: each
 * exception instance carries its own recordedness, so nested guards
 * across components (emit() listeners) can never mis-attribute errors.
 *
 * Author code catching ValidationException catches this transparently.
 */
class RecordedValidationException extends ValidationException {}
