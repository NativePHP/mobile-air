/*
 * Host-registered PHP functions for NativePHP.
 *
 * All PHP functions (nativephp_call, nativephp_element_*, etc.) are
 * registered via php_embed_module.additional_functions, eliminating the
 * need for a separate .so extension and dlopen/dlsym overhead.
 */

#ifndef NATIVE_FUNCTIONS_H
#define NATIVE_FUNCTIONS_H

#include "PHP.h"

#ifdef __cplusplus
extern "C" {
#endif

/* Function entry array — set as php_embed_module.additional_functions */
extern zend_function_entry nativephp_functions[];

#ifdef __cplusplus
}
#endif

#endif /* NATIVE_FUNCTIONS_H */
