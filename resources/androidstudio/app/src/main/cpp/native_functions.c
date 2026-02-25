/*
 * Host-registered PHP functions for NativePHP.
 *
 * Contains all PHP_FUNCTION implementations that were previously in
 * the nativephp extension (.so). Now compiled directly into
 * libphp_wrapper.so and registered via additional_functions.
 *
 * nativephp_call / nativephp_can: call JNI directly (same .so)
 * nativephp_element_*: element runtime (flat buffer builder)
 */

#include "PHP.h"
#include "php_embed.h"
#include <stdint.h>
#include <stdatomic.h>
#include <pthread.h>
#include <string.h>
#include <stdlib.h>
#include <time.h>
#include <errno.h>
#include <android/log.h>

#define NF_TAG "NativePHP_Host"
#define NF_LOGI(...) __android_log_print(ANDROID_LOG_INFO,  NF_TAG, __VA_ARGS__)
#define NF_LOGE(...) __android_log_print(ANDROID_LOG_ERROR, NF_TAG, __VA_ARGS__)

/* ══════════════════════════════════════════════════════
 * Bridge Functions — NativePHPCall / NativePHPCan
 *
 * These are now direct C calls within the same .so,
 * no more dlopen/dlsym per invocation.
 * ══════════════════════════════════════════════════════ */

/* Defined in bridge_jni.cpp — same library */
extern const char* NativePHPCall(const char* functionName, const char* parametersJSON);
extern int NativePHPCan(const char* functionName);

/* ══════════════════════════════════════════════════════
 * Constants (from nativephp_ui.h)
 * ══════════════════════════════════════════════════════ */

/* Value Type Tags */
#define NPUI_VAL_U8           0
#define NPUI_VAL_U16          1
#define NPUI_VAL_U32          2
#define NPUI_VAL_I32          3
#define NPUI_VAL_F32          4
#define NPUI_VAL_BOOL         5
#define NPUI_VAL_STRING       6
#define NPUI_VAL_COLOR        7
#define NPUI_VAL_CALLBACK     8
#define NPUI_VAL_STRING_ARRAY 9

/* Interned Prop Key Indices */
#define NPUI_KEY_FALLBACK     0xFF
#define NPUI_KEY_COUNT        44

#define NPUI_KEY_TEXT             0
#define NPUI_KEY_LABEL            1
#define NPUI_KEY_VALUE            2
#define NPUI_KEY_COLOR            3
#define NPUI_KEY_ON_PRESS         4
#define NPUI_KEY_ON_CHANGE        5
#define NPUI_KEY_ON_SUBMIT        6
#define NPUI_KEY_ON_DISMISS       7
#define NPUI_KEY_DISABLED         8
#define NPUI_KEY_PLACEHOLDER      9
#define NPUI_KEY_FONT_SIZE        10
#define NPUI_KEY_FONT_WEIGHT      11
#define NPUI_KEY_TEXT_ALIGN       12
#define NPUI_KEY_MAX_LINES        13
#define NPUI_KEY_SRC              14
#define NPUI_KEY_FIT              15
#define NPUI_KEY_TINT_COLOR       16
#define NPUI_KEY_LABEL_COLOR      17
#define NPUI_KEY_KEYBOARD         18
#define NPUI_KEY_SECURE           19
#define NPUI_KEY_MAX_LENGTH       20
#define NPUI_KEY_MULTILINE        21
#define NPUI_KEY_HORIZONTAL       22
#define NPUI_KEY_SHOWS_INDICATORS 23
#define NPUI_KEY_MIN              24
#define NPUI_KEY_MAX              25
#define NPUI_KEY_STEP             26
#define NPUI_KEY_TRACK_COLOR      27
#define NPUI_KEY_SIZE             28
#define NPUI_KEY_NAME             29
#define NPUI_KEY_OPTIONS          30
#define NPUI_KEY_COUNT_PROP       31
#define NPUI_KEY_TEXT_COLOR       32
#define NPUI_KEY_VARIANT          33
#define NPUI_KEY_HEADLINE         34
#define NPUI_KEY_SUPPORTING       35
#define NPUI_KEY_OVERLINE         36
#define NPUI_KEY_LEADING_ICON     37
#define NPUI_KEY_TRAILING_ICON    38
#define NPUI_KEY_HEADLINE_COLOR   39
#define NPUI_KEY_SUPPORTING_COLOR 40
#define NPUI_KEY_SELECTED_INDEX   41
#define NPUI_KEY_ICON             42
#define NPUI_KEY_VISIBLE          43

/* Size Modes */
#define NPUI_SIZE_FIXED       0
#define NPUI_SIZE_WRAP        1
#define NPUI_SIZE_FILL        2
#define NPUI_SIZE_PERCENT     3

/* Event Types */
#define NPUI_EVENT_PRESS         0
#define NPUI_EVENT_LONG_PRESS    1
#define NPUI_EVENT_TEXT_CHANGE   2
#define NPUI_EVENT_TOGGLE_CHANGE 3
#define NPUI_EVENT_SUBMIT        4
#define NPUI_EVENT_FOCUS         5
#define NPUI_EVENT_BLUR          6
#define NPUI_EVENT_SCROLL        7
#define NPUI_EVENT_SYSTEM_BACK   8
#define NPUI_EVENT_SLIDER_CHANGE 9
#define NPUI_EVENT_CHECKBOX_CHANGE 10
#define NPUI_EVENT_RADIO_CHANGE  11
#define NPUI_EVENT_SELECT_CHANGE 12
#define NPUI_EVENT_TAB_CHANGE    13
#define NPUI_EVENT_SHEET_DISMISS 14
#define NPUI_EVENT_HOT_RELOAD    15

#define NPUI_EVENT_MAGIC         0x4E504556  /* "NPEV" */

/* ══════════════════════════════════════════════════════
 * Binary Writer/Reader (inline, from nativephp_ui.h)
 * ══════════════════════════════════════════════════════ */

typedef struct {
    uint8_t *buf;
    size_t   capacity;
    size_t   pos;
    int      overflow;
} npui_writer_t;

static inline void npui_writer_init(npui_writer_t *w, uint8_t *buf, size_t capacity) {
    w->buf = buf; w->capacity = capacity; w->pos = 0; w->overflow = 0;
}

static inline void npui_write_u8(npui_writer_t *w, uint8_t val) {
    if (w->pos + 1 > w->capacity) { w->overflow = 1; return; }
    w->buf[w->pos++] = val;
}

static inline void npui_write_u16(npui_writer_t *w, uint16_t val) {
    if (w->pos + 2 > w->capacity) { w->overflow = 1; return; }
    memcpy(w->buf + w->pos, &val, 2); w->pos += 2;
}

static inline void npui_write_u32(npui_writer_t *w, uint32_t val) {
    if (w->pos + 4 > w->capacity) { w->overflow = 1; return; }
    memcpy(w->buf + w->pos, &val, 4); w->pos += 4;
}

static inline void npui_write_f32(npui_writer_t *w, float val) {
    if (w->pos + 4 > w->capacity) { w->overflow = 1; return; }
    memcpy(w->buf + w->pos, &val, 4); w->pos += 4;
}

static inline void npui_write_str(npui_writer_t *w, const char *str, size_t len) {
    if (len > 0xFFFF) len = 0xFFFF;
    npui_write_u16(w, (uint16_t)len);
    if (w->pos + len > w->capacity) { w->overflow = 1; return; }
    if (len > 0) { memcpy(w->buf + w->pos, str, len); w->pos += len; }
}

typedef struct {
    const uint8_t *buf;
    size_t         size;
    size_t         pos;
    int            error;
} npui_reader_t;

static inline void npui_reader_init(npui_reader_t *r, const uint8_t *buf, size_t size) {
    r->buf = buf; r->size = size; r->pos = 0; r->error = 0;
}

static inline uint8_t npui_read_u8(npui_reader_t *r) {
    if (r->pos + 1 > r->size) { r->error = 1; return 0; }
    return r->buf[r->pos++];
}

static inline uint16_t npui_read_u16(npui_reader_t *r) {
    if (r->pos + 2 > r->size) { r->error = 1; return 0; }
    uint16_t val; memcpy(&val, r->buf + r->pos, 2); r->pos += 2; return val;
}

static inline uint32_t npui_read_u32(npui_reader_t *r) {
    if (r->pos + 4 > r->size) { r->error = 1; return 0; }
    uint32_t val; memcpy(&val, r->buf + r->pos, 4); r->pos += 4; return val;
}

static inline uint64_t npui_read_u64(npui_reader_t *r) {
    if (r->pos + 8 > r->size) { r->error = 1; return 0; }
    uint64_t val; memcpy(&val, r->buf + r->pos, 8); r->pos += 8; return val;
}

static inline float npui_read_f32(npui_reader_t *r) {
    if (r->pos + 4 > r->size) { r->error = 1; return 0; }
    float val; memcpy(&val, r->buf + r->pos, 4); r->pos += 4; return val;
}

static inline const char* npui_read_str(npui_reader_t *r, uint16_t *out_len) {
    uint16_t len = npui_read_u16(r);
    if (r->error || r->pos + len > r->size) { r->error = 1; *out_len = 0; return ""; }
    const char *str = (const char*)(r->buf + r->pos);
    r->pos += len; *out_len = len; return str;
}

/* ══════════════════════════════════════════════════════
 * Element Runtime — Data Structures
 * ══════════════════════════════════════════════════════ */

#define NPHP_ELEMENT_MAGIC       0x454C4531  /* "ELE1" */
#define NPHP_FLAT_BUFFER_SIZE    (256 * 1024)
#define NPHP_PROP_BUFFER_SIZE    (512 * 1024)
#define NPHP_EVENT_BUFFER_SIZE   (256 * 1024)

typedef struct nphp_element_region {
    uint32_t magic;
    _Atomic uint32_t tree_version;
    _Atomic uint32_t shutdown;
    _Atomic uint32_t running;

    zval *current_tree;
    _Atomic uint32_t node_count;

    uint8_t *flat_buffer;
    _Atomic uint32_t flat_buffer_size;
    uint8_t *prop_buffer;
    _Atomic uint32_t prop_buffer_size;

    char type_table[4096];
    uint16_t type_offsets[128];
    uint8_t type_count;

    pthread_mutex_t tree_mutex;
    pthread_cond_t  tree_cond;

    _Atomic uint32_t event_size;
    _Atomic uint32_t event_count;
    pthread_mutex_t event_mutex;
    pthread_cond_t  event_cond;
    uint8_t event_buffer[NPHP_EVENT_BUFFER_SIZE];
} nphp_element_region_t;

/* Flat node layout — 108 bytes packed */
typedef struct nphp_flat_node {
    uint32_t id;
    uint16_t type_idx;
    uint16_t child_count;
    uint32_t first_child_offset;
    uint32_t on_press;
    uint32_t on_long_press;
    float    width;
    uint8_t  width_mode;
    float    height;
    uint8_t  height_mode;
    uint8_t  align_self;
    uint8_t  align_items;
    uint8_t  justify_content;
    uint8_t  safe_area;
    float    padding[4];
    float    margin[4];
    float    flex_grow;
    float    flex_shrink;
    float    gap;
    uint32_t bg_color;
    float    border_radius;
    float    border_width;
    uint32_t border_color;
    float    opacity;
    float    elevation;
    uint32_t prop_offset;
    uint16_t prop_size;
} __attribute__((packed)) nphp_flat_node_t;

/* ── Global State ── */

static nphp_element_region_t *g_element_region = NULL;
static pthread_mutex_t g_region_mutex = PTHREAD_MUTEX_INITIALIZER;

/* ── Register/Unregister with JNI bridge (same .so, direct calls) ── */

extern void NativeElement_RegisterRegion(void* ptr);
extern void NativeElement_UnregisterRegion(void);

/* Direct JNI push — calls NativeElementBridge.postTreeUpdate() from PHP thread */
extern void NativeElement_PostTreeUpdate(void);

/* ══════════════════════════════════════════════════════
 * Helpers
 * ══════════════════════════════════════════════════════ */

static zval* el_ht_get(HashTable *ht, const char *key) {
    return zend_hash_str_find(ht, key, strlen(key));
}

static float el_zval_get_float(zval *z) {
    if (z == NULL) return 0.0f;
    switch (Z_TYPE_P(z)) {
        case IS_DOUBLE: return (float)Z_DVAL_P(z);
        case IS_LONG:   return (float)Z_LVAL_P(z);
        case IS_STRING: return (float)atof(Z_STRVAL_P(z));
        default:        return 0.0f;
    }
}

static uint32_t el_zval_get_u32(zval *z) {
    if (z == NULL) return 0;
    switch (Z_TYPE_P(z)) {
        case IS_LONG:   return (uint32_t)Z_LVAL_P(z);
        case IS_DOUBLE: return (uint32_t)Z_DVAL_P(z);
        default:        return 0;
    }
}

static uint32_t el_parse_color(zval *z) {
    if (z == NULL) return 0xFF000000;
    if (Z_TYPE_P(z) == IS_LONG) return (uint32_t)Z_LVAL_P(z);
    if (Z_TYPE_P(z) == IS_STRING) {
        const char *s = Z_STRVAL_P(z);
        size_t len = Z_STRLEN_P(z);
        if (len > 0 && s[0] == '#') { s++; len--; }
        if (len == 6) return 0xFF000000 | (uint32_t)strtoul(s, NULL, 16);
        if (len == 8) return (uint32_t)strtoul(s, NULL, 16);
    }
    return 0xFF000000;
}

static uint8_t el_resolve_size_mode(const char *str, size_t len) {
    if (len == 4 && memcmp(str, "wrap", 4) == 0) return NPUI_SIZE_WRAP;
    if (len == 4 && memcmp(str, "fill", 4) == 0) return NPUI_SIZE_FILL;
    if (len == 7 && memcmp(str, "percent", 7) == 0) return NPUI_SIZE_PERCENT;
    return NPUI_SIZE_FIXED;
}

/* ══════════════════════════════════════════════════════
 * Prop Key Table (for interned prop serialization)
 * ══════════════════════════════════════════════════════ */

static const char *prop_key_table[] = {
    "text", "label", "value", "color", "on_press", "on_change",
    "on_submit", "on_dismiss", "disabled", "placeholder",
    "font_size", "font_weight", "text_align", "max_lines",
    "src", "fit", "tint_color", "label_color", "keyboard",
    "secure", "max_length", "multiline", "horizontal",
    "shows_indicators", "min", "max", "step", "track_color",
    "size", "name", "options", "count", "text_color", "variant",
    "headline", "supporting", "overline", "leading_icon",
    "trailing_icon", "headline_color", "supporting_color",
    "selected_index", "icon", "visible",
};

static uint8_t lookup_prop_key(const char *key, size_t key_len) {
    for (uint8_t i = 0; i < NPUI_KEY_COUNT; i++) {
        if (strlen(prop_key_table[i]) == key_len &&
            memcmp(prop_key_table[i], key, key_len) == 0) {
            return i;
        }
    }
    return NPUI_KEY_FALLBACK;
}

/* ══════════════════════════════════════════════════════
 * Generic Props Serializer
 * ══════════════════════════════════════════════════════ */

static void npui_write_generic_props(npui_writer_t *w, HashTable *ht) {
    uint32_t count = zend_hash_num_elements(ht);
    if (count > 255) count = 255;
    npui_write_u8(w, (uint8_t)count);

    zend_string *key;
    zval *val;
    uint8_t written = 0;

    ZEND_HASH_FOREACH_STR_KEY_VAL(ht, key, val) {
        if (written >= count) break;

        if (key == NULL) { written++; continue; }

        /* Write key index or fallback string */
        uint8_t idx = lookup_prop_key(ZSTR_VAL(key), ZSTR_LEN(key));
        npui_write_u8(w, idx);
        if (idx == NPUI_KEY_FALLBACK) {
            npui_write_str(w, ZSTR_VAL(key), ZSTR_LEN(key));
        }

        /* Write typed value */
        switch (Z_TYPE_P(val)) {
            case IS_LONG: {
                zend_long v = Z_LVAL_P(val);
                if (v >= 0 && v <= 255) {
                    npui_write_u8(w, NPUI_VAL_U8);
                    npui_write_u8(w, (uint8_t)v);
                } else if (v >= 0 && v <= 0xFFFF) {
                    npui_write_u8(w, NPUI_VAL_U16);
                    npui_write_u16(w, (uint16_t)v);
                } else if (v >= 0) {
                    npui_write_u8(w, NPUI_VAL_U32);
                    npui_write_u32(w, (uint32_t)v);
                } else {
                    npui_write_u8(w, NPUI_VAL_I32);
                    npui_write_u32(w, (uint32_t)(int32_t)v);
                }
                break;
            }
            case IS_DOUBLE:
                npui_write_u8(w, NPUI_VAL_F32);
                npui_write_f32(w, (float)Z_DVAL_P(val));
                break;
            case IS_TRUE:
                npui_write_u8(w, NPUI_VAL_BOOL);
                npui_write_u8(w, 1);
                break;
            case IS_FALSE:
                npui_write_u8(w, NPUI_VAL_BOOL);
                npui_write_u8(w, 0);
                break;
            case IS_STRING: {
                /* Check if it looks like a color (#RRGGBB or #AARRGGBB) */
                const char *s = Z_STRVAL_P(val);
                size_t slen = Z_STRLEN_P(val);
                if (slen >= 7 && s[0] == '#') {
                    /* Check if key is a known color key */
                    uint8_t is_color = (idx == NPUI_KEY_COLOR ||
                                       idx == NPUI_KEY_TINT_COLOR ||
                                       idx == NPUI_KEY_LABEL_COLOR ||
                                       idx == NPUI_KEY_TEXT_COLOR ||
                                       idx == NPUI_KEY_TRACK_COLOR ||
                                       idx == NPUI_KEY_HEADLINE_COLOR ||
                                       idx == NPUI_KEY_SUPPORTING_COLOR);
                    if (is_color) {
                        npui_write_u8(w, NPUI_VAL_COLOR);
                        npui_write_u32(w, el_parse_color(val));
                        break;
                    }
                }
                npui_write_u8(w, NPUI_VAL_STRING);
                npui_write_str(w, Z_STRVAL_P(val), Z_STRLEN_P(val));
                break;
            }
            case IS_ARRAY: {
                /* Check if it's a string array */
                HashTable *arr = Z_ARRVAL_P(val);
                int all_strings = 1;
                zval *elem;
                ZEND_HASH_FOREACH_VAL(arr, elem) {
                    if (Z_TYPE_P(elem) != IS_STRING) { all_strings = 0; break; }
                } ZEND_HASH_FOREACH_END();

                if (all_strings) {
                    npui_write_u8(w, NPUI_VAL_STRING_ARRAY);
                    uint32_t arr_count = zend_hash_num_elements(arr);
                    npui_write_u16(w, (uint16_t)arr_count);
                    ZEND_HASH_FOREACH_VAL(arr, elem) {
                        npui_write_str(w, Z_STRVAL_P(elem), Z_STRLEN_P(elem));
                    } ZEND_HASH_FOREACH_END();
                } else {
                    /* Fallback: skip unsupported array types */
                    npui_write_u8(w, NPUI_VAL_U8);
                    npui_write_u8(w, 0);
                }
                break;
            }
            default:
                npui_write_u8(w, NPUI_VAL_U8);
                npui_write_u8(w, 0);
                break;
        }

        written++;
        if (w->overflow) break;
    } ZEND_HASH_FOREACH_END();
}

/* ══════════════════════════════════════════════════════
 * Type Interning
 * ══════════════════════════════════════════════════════ */

static uint16_t nphp_intern_type(const char *type, size_t len) {
    nphp_element_region_t *r = g_element_region;
    if (!r) return 0;

    for (uint8_t i = 0; i < r->type_count; i++) {
        const char *existing = r->type_table + r->type_offsets[i];
        if (strlen(existing) == len && memcmp(existing, type, len) == 0) {
            return (uint16_t)i;
        }
    }

    if (r->type_count >= 128) return 0;

    uint16_t offset = 0;
    if (r->type_count > 0) {
        uint16_t prev = r->type_offsets[r->type_count - 1];
        offset = prev + (uint16_t)strlen(r->type_table + prev) + 1;
    }

    if (offset + len + 1 > sizeof(r->type_table)) return 0;

    memcpy(r->type_table + offset, type, len);
    r->type_table[offset + len] = '\0';
    r->type_offsets[r->type_count] = offset;

    return (uint16_t)(r->type_count++);
}

/* ══════════════════════════════════════════════════════
 * zval Accessor Functions
 * ══════════════════════════════════════════════════════ */

static const char* nphp_node_type(zval *node, size_t *out_len) {
    zval *z = zend_hash_str_find(Z_ARRVAL_P(node), "type", 4);
    if (z && Z_TYPE_P(z) == IS_STRING) {
        *out_len = Z_STRLEN_P(z);
        return Z_STRVAL_P(z);
    }
    *out_len = 6;
    return "column";
}

static uint32_t nphp_node_on_press(zval *node) {
    zval *z = zend_hash_str_find(Z_ARRVAL_P(node), "on_press", 8);
    if (!z) z = zend_hash_str_find(Z_ARRVAL_P(node), "onPress", 7);
    return z ? el_zval_get_u32(z) : 0;
}

static uint32_t nphp_node_on_long_press(zval *node) {
    zval *z = zend_hash_str_find(Z_ARRVAL_P(node), "on_long_press", 13);
    if (!z) z = zend_hash_str_find(Z_ARRVAL_P(node), "onLongPress", 11);
    return z ? el_zval_get_u32(z) : 0;
}

/* ══════════════════════════════════════════════════════
 * Fill Layout / Style Fields
 * ══════════════════════════════════════════════════════ */

static void fill_layout(nphp_flat_node_t *fn, HashTable *ht) {
    zval *z;

    z = el_ht_get(ht, "width");
    if (z && Z_TYPE_P(z) == IS_STRING) {
        fn->width = 0.0f;
        fn->width_mode = el_resolve_size_mode(Z_STRVAL_P(z), Z_STRLEN_P(z));
    } else {
        fn->width = z ? el_zval_get_float(z) : 0.0f;
        fn->width_mode = z ? NPUI_SIZE_FIXED : NPUI_SIZE_WRAP;
    }

    z = el_ht_get(ht, "height");
    if (z && Z_TYPE_P(z) == IS_STRING) {
        fn->height = 0.0f;
        fn->height_mode = el_resolve_size_mode(Z_STRVAL_P(z), Z_STRLEN_P(z));
    } else {
        fn->height = z ? el_zval_get_float(z) : 0.0f;
        fn->height_mode = z ? NPUI_SIZE_FIXED : NPUI_SIZE_WRAP;
    }

    z = el_ht_get(ht, "padding");
    if (z && Z_TYPE_P(z) == IS_ARRAY) {
        HashTable *p = Z_ARRVAL_P(z);
        zval *pt = zend_hash_index_find(p, 0);
        zval *pr = zend_hash_index_find(p, 1);
        zval *pb = zend_hash_index_find(p, 2);
        zval *pl = zend_hash_index_find(p, 3);
        fn->padding[0] = pt ? el_zval_get_float(pt) : 0.0f;
        fn->padding[1] = pr ? el_zval_get_float(pr) : 0.0f;
        fn->padding[2] = pb ? el_zval_get_float(pb) : 0.0f;
        fn->padding[3] = pl ? el_zval_get_float(pl) : 0.0f;
    } else if (z) {
        float val = el_zval_get_float(z);
        fn->padding[0] = fn->padding[1] = fn->padding[2] = fn->padding[3] = val;
    } else {
        fn->padding[0] = fn->padding[1] = fn->padding[2] = fn->padding[3] = 0.0f;
    }

    z = el_ht_get(ht, "margin");
    if (z && Z_TYPE_P(z) == IS_ARRAY) {
        HashTable *m = Z_ARRVAL_P(z);
        zval *mt = zend_hash_index_find(m, 0);
        zval *mr = zend_hash_index_find(m, 1);
        zval *mb = zend_hash_index_find(m, 2);
        zval *ml = zend_hash_index_find(m, 3);
        fn->margin[0] = mt ? el_zval_get_float(mt) : 0.0f;
        fn->margin[1] = mr ? el_zval_get_float(mr) : 0.0f;
        fn->margin[2] = mb ? el_zval_get_float(mb) : 0.0f;
        fn->margin[3] = ml ? el_zval_get_float(ml) : 0.0f;
    } else if (z) {
        float val = el_zval_get_float(z);
        fn->margin[0] = fn->margin[1] = fn->margin[2] = fn->margin[3] = val;
    } else {
        fn->margin[0] = fn->margin[1] = fn->margin[2] = fn->margin[3] = 0.0f;
    }

    z = el_ht_get(ht, "flex_grow");
    fn->flex_grow = z ? el_zval_get_float(z) : 0.0f;

    z = el_ht_get(ht, "flex_shrink");
    fn->flex_shrink = z ? el_zval_get_float(z) : 1.0f;

    z = el_ht_get(ht, "align_self");
    fn->align_self = z ? (uint8_t)el_zval_get_u32(z) : 0;

    z = el_ht_get(ht, "align_items");
    fn->align_items = z ? (uint8_t)el_zval_get_u32(z) : 0;

    z = el_ht_get(ht, "justify_content");
    fn->justify_content = z ? (uint8_t)el_zval_get_u32(z) : 0;

    z = el_ht_get(ht, "gap");
    fn->gap = z ? el_zval_get_float(z) : 0.0f;

    z = el_ht_get(ht, "safe_area");
    fn->safe_area = z ? (uint8_t)el_zval_get_u32(z) : 0;
}

static void fill_style(nphp_flat_node_t *fn, HashTable *ht) {
    zval *z;

    z = el_ht_get(ht, "bg_color");
    if (!z) z = el_ht_get(ht, "background_color");
    fn->bg_color = z ? el_parse_color(z) : 0x00000000;

    z = el_ht_get(ht, "border_radius");
    fn->border_radius = z ? el_zval_get_float(z) : 0.0f;

    z = el_ht_get(ht, "border_width");
    fn->border_width = z ? el_zval_get_float(z) : 0.0f;

    z = el_ht_get(ht, "border_color");
    fn->border_color = z ? el_parse_color(z) : 0xFF000000;

    z = el_ht_get(ht, "opacity");
    fn->opacity = z ? el_zval_get_float(z) : 1.0f;

    z = el_ht_get(ht, "elevation");
    fn->elevation = z ? el_zval_get_float(z) : 0.0f;
}

/* ══════════════════════════════════════════════════════
 * Bulk Tree Builder (recursive DFS pre-order)
 * ══════════════════════════════════════════════════════ */

static int build_node(zval *node, size_t *flat_pos, npui_writer_t *pw, uint32_t *next_id) {
    nphp_element_region_t *r = g_element_region;

    if (Z_TYPE_P(node) != IS_ARRAY) return 0;

    if (*flat_pos + sizeof(nphp_flat_node_t) > NPHP_FLAT_BUFFER_SIZE) {
        NF_LOGE("Flat buffer overflow at node %u", *next_id);
        return 0;
    }

    nphp_flat_node_t *fn = (nphp_flat_node_t *)(r->flat_buffer + *flat_pos);
    memset(fn, 0, sizeof(nphp_flat_node_t));

    HashTable *ht = Z_ARRVAL_P(node);

    zval *z_id = el_ht_get(ht, "id");
    fn->id = z_id ? el_zval_get_u32(z_id) : (*next_id)++;

    size_t type_len;
    const char *type_str = nphp_node_type(node, &type_len);
    fn->type_idx = nphp_intern_type(type_str, type_len);

    fn->on_press = nphp_node_on_press(node);
    fn->on_long_press = nphp_node_on_long_press(node);

    zval *z_layout = el_ht_get(ht, "layout");
    if (z_layout && Z_TYPE_P(z_layout) == IS_ARRAY) {
        fill_layout(fn, Z_ARRVAL_P(z_layout));
    } else {
        fn->width_mode = NPUI_SIZE_WRAP;
        fn->height_mode = NPUI_SIZE_WRAP;
        fn->flex_shrink = 1.0f;
        fn->opacity = 1.0f;
    }

    zval *z_style = el_ht_get(ht, "style");
    if (z_style && Z_TYPE_P(z_style) == IS_ARRAY) {
        fill_style(fn, Z_ARRVAL_P(z_style));
    } else {
        fn->opacity = 1.0f;
    }

    zval *z_props = el_ht_get(ht, "props");
    if (z_props && Z_TYPE_P(z_props) == IS_ARRAY &&
        zend_hash_num_elements(Z_ARRVAL_P(z_props)) > 0) {
        fn->prop_offset = (uint32_t)pw->pos;
        npui_write_generic_props(pw, Z_ARRVAL_P(z_props));
        fn->prop_size = (uint16_t)(pw->pos - fn->prop_offset);
    } else {
        fn->prop_offset = 0;
        fn->prop_size = 0;
    }

    size_t my_pos = *flat_pos;
    *flat_pos += sizeof(nphp_flat_node_t);

    zval *z_children = el_ht_get(ht, "children");
    if (z_children && Z_TYPE_P(z_children) == IS_ARRAY) {
        HashTable *children = Z_ARRVAL_P(z_children);
        fn->child_count = (uint16_t)zend_hash_num_elements(children);
        fn->first_child_offset = (uint32_t)*flat_pos;

        zval *child;
        ZEND_HASH_FOREACH_VAL(children, child) {
            if (!build_node(child, flat_pos, pw, next_id)) return 0;
            if (pw->overflow) return 0;
        } ZEND_HASH_FOREACH_END();
    } else {
        fn->child_count = 0;
        fn->first_child_offset = 0;
    }

    return 1;
}

/* ══════════════════════════════════════════════════════
 * Element Lifecycle
 * ══════════════════════════════════════════════════════ */

static int nphp_element_init(void) {
    pthread_mutex_lock(&g_region_mutex);

    if (g_element_region != NULL) {
        /* Old region still alive — wait for shutdown if flagged */
        if (atomic_load(&g_element_region->shutdown)) {
            NF_LOGI("init: old region shutting down, waiting...");
            pthread_mutex_unlock(&g_region_mutex);
            /* Brief sleep to let old thread finish cleanup */
            struct timespec ts = {0, 50000000}; /* 50ms */
            nanosleep(&ts, NULL);
            pthread_mutex_lock(&g_region_mutex);
            if (g_element_region != NULL) {
                NF_LOGE("init: old region STILL alive after wait, forcing cleanup");
                /* Don't free — old thread will; just clear pointer */
                g_element_region = NULL;
                NativeElement_UnregisterRegion();
            }
        } else {
            NF_LOGI("Element region already initialized");
            pthread_mutex_unlock(&g_region_mutex);
            return 1;
        }
    }

    nphp_element_region_t *r = (nphp_element_region_t *)calloc(1, sizeof(nphp_element_region_t));
    if (!r) {
        NF_LOGE("Failed to allocate element region");
        pthread_mutex_unlock(&g_region_mutex);
        return 0;
    }

    r->magic = NPHP_ELEMENT_MAGIC;
    atomic_store(&r->tree_version, 0);
    atomic_store(&r->shutdown, 0);
    atomic_store(&r->running, 1);
    atomic_store(&r->node_count, 0);
    atomic_store(&r->flat_buffer_size, 0);
    atomic_store(&r->prop_buffer_size, 0);
    atomic_store(&r->event_size, 0);
    atomic_store(&r->event_count, 0);
    r->current_tree = NULL;
    r->type_count = 0;

    r->flat_buffer = (uint8_t *)malloc(NPHP_FLAT_BUFFER_SIZE);
    r->prop_buffer = (uint8_t *)malloc(NPHP_PROP_BUFFER_SIZE);
    if (!r->flat_buffer || !r->prop_buffer) {
        NF_LOGE("Failed to allocate buffers");
        free(r->flat_buffer);
        free(r->prop_buffer);
        free(r);
        pthread_mutex_unlock(&g_region_mutex);
        return 0;
    }

    pthread_mutex_init(&r->tree_mutex, NULL);
    pthread_cond_init(&r->tree_cond, NULL);
    pthread_mutex_init(&r->event_mutex, NULL);
    pthread_cond_init(&r->event_cond, NULL);

    g_element_region = r;

    pthread_mutex_unlock(&g_region_mutex);

    NF_LOGI("Element region initialized (region=%p)", r);

    /* Register directly — same .so, no dlsym needed */
    NativeElement_RegisterRegion(r);

    return 1;
}

static void nphp_element_shutdown(void) {
    NF_LOGI("Element shutdown starting");
    pthread_mutex_lock(&g_region_mutex);

    nphp_element_region_t *r = g_element_region;
    if (!r) {
        pthread_mutex_unlock(&g_region_mutex);
        return;
    }

    atomic_store(&r->shutdown, 1);
    atomic_store(&r->running, 0);
    pthread_cond_broadcast(&r->event_cond);
    pthread_cond_broadcast(&r->tree_cond);

    /* Unregister from JNI bridge — direct call */
    NativeElement_UnregisterRegion();

    if (r->current_tree) {
        zval_ptr_dtor(r->current_tree);
        efree(r->current_tree);
        r->current_tree = NULL;
    }

    pthread_mutex_destroy(&r->tree_mutex);
    pthread_cond_destroy(&r->tree_cond);
    pthread_mutex_destroy(&r->event_mutex);
    pthread_cond_destroy(&r->event_cond);

    free(r->flat_buffer);
    free(r->prop_buffer);
    free(r);

    g_element_region = NULL;

    pthread_mutex_unlock(&g_region_mutex);

    NF_LOGI("Element region destroyed");
}

static void nphp_element_reset(void) {
    nphp_element_region_t *r = g_element_region;
    if (!r) return;

    pthread_mutex_lock(&r->tree_mutex);
    pthread_mutex_lock(&r->event_mutex);

    atomic_store(&r->flat_buffer_size, 0);
    atomic_store(&r->prop_buffer_size, 0);
    atomic_store(&r->node_count, 0);
    atomic_store(&r->event_size, 0);
    atomic_store(&r->event_count, 0);

    /* Clear type table so the next publish re-interns from scratch.
     * This ensures the Kotlin side always reads a fresh type table
     * that matches the flat buffer's type indices. */
    r->type_count = 0;

    pthread_mutex_unlock(&r->event_mutex);
    pthread_mutex_unlock(&r->tree_mutex);

    NF_LOGI("reset: buffers and type table cleared");
}

static int nphp_element_publish(zval *tree) {
    nphp_element_region_t *r = g_element_region;
    if (!r) {
        NF_LOGE("publish: region is NULL");
        return 0;
    }

    size_t flat_pos = 0;
    npui_writer_t pw;
    npui_writer_init(&pw, r->prop_buffer, NPHP_PROP_BUFFER_SIZE);
    uint32_t next_id = 1;

    if (!build_node(tree, &flat_pos, &pw, &next_id)) {
        NF_LOGE("build_node failed (overflow=%d)", pw.overflow);
        return 0;
    }

    uint32_t node_count = (uint32_t)(flat_pos / sizeof(nphp_flat_node_t));
    atomic_store(&r->flat_buffer_size, (uint32_t)flat_pos);
    atomic_store(&r->prop_buffer_size, (uint32_t)pw.pos);
    atomic_store(&r->node_count, node_count);

    NF_LOGI("publish: nodes=%u flat=%u prop=%u types=%u ver=%u",
            node_count, (uint32_t)flat_pos, (uint32_t)pw.pos,
            (uint32_t)r->type_count,
            atomic_load(&r->tree_version) + 1);

    if (r->current_tree) {
        zval_ptr_dtor(r->current_tree);
        efree(r->current_tree);
    }
    r->current_tree = (zval *)emalloc(sizeof(zval));
    ZVAL_COPY(r->current_tree, tree);

    atomic_fetch_add(&r->tree_version, 1);

    /* Direct JNI push — call Kotlin's postTreeUpdate() on this thread.
     * Replaces the old condvar signal + watcher thread polling model.
     * postTreeUpdate() reads the flat buffer via JNI, parses the tree,
     * and Handler.post()s it to the main thread for Compose recomposition. */
    NativeElement_PostTreeUpdate();

    return 1;
}

static int nphp_element_wait_event(int timeout_ms, zval *result) {
    nphp_element_region_t *r = g_element_region;
    if (!r) return 0;

    pthread_mutex_lock(&r->event_mutex);

    while (atomic_load(&r->event_count) == 0 &&
           !atomic_load(&r->shutdown)) {

        if (timeout_ms < 0) {
            pthread_cond_wait(&r->event_cond, &r->event_mutex);
        } else {
            struct timespec ts;
            clock_gettime(CLOCK_REALTIME, &ts);
            ts.tv_sec  += timeout_ms / 1000;
            ts.tv_nsec += (timeout_ms % 1000) * 1000000;
            if (ts.tv_nsec >= 1000000000) {
                ts.tv_sec++;
                ts.tv_nsec -= 1000000000;
            }

            int rc = pthread_cond_timedwait(&r->event_cond, &r->event_mutex, &ts);
            if (rc == ETIMEDOUT) {
                pthread_mutex_unlock(&r->event_mutex);
                return 0;
            }
        }
    }

    if (atomic_load(&r->shutdown)) {
        pthread_mutex_unlock(&r->event_mutex);
        return -1;
    }

    uint32_t event_size = atomic_load(&r->event_size);
    uint8_t *event_buf = r->event_buffer;

    npui_reader_t rd;
    npui_reader_init(&rd, event_buf, event_size);

    uint32_t magic = npui_read_u32(&rd);
    if (rd.error || magic != NPUI_EVENT_MAGIC) {
        atomic_store(&r->event_count, 0);
        atomic_store(&r->event_size, 0);
        pthread_mutex_unlock(&r->event_mutex);
        return 0;
    }

    array_init(result);

    uint8_t  type        = npui_read_u8(&rd);
    uint32_t callback_id = npui_read_u32(&rd);
    uint32_t node_id     = npui_read_u32(&rd);
    uint64_t timestamp   = npui_read_u64(&rd);
    uint16_t data_size   = npui_read_u16(&rd);

    if (type == NPUI_EVENT_HOT_RELOAD) {
        NF_LOGI("wait_event: received HOT_RELOAD event");
    }

    add_assoc_long(result, "type", type);
    add_assoc_long(result, "callback_id", callback_id);
    add_assoc_long(result, "node_id", node_id);
    add_assoc_long(result, "timestamp", (zend_long)timestamp);

    if (!rd.error && data_size > 0) {
        switch (type) {
            case NPUI_EVENT_PRESS:
            case NPUI_EVENT_LONG_PRESS: {
                float x = npui_read_f32(&rd);
                float y = npui_read_f32(&rd);
                add_assoc_double(result, "x", x);
                add_assoc_double(result, "y", y);
                break;
            }
            case NPUI_EVENT_TEXT_CHANGE:
            case NPUI_EVENT_SUBMIT: {
                uint16_t len;
                const char *text = npui_read_str(&rd, &len);
                add_assoc_stringl(result, "text", text, len);
                break;
            }
            case NPUI_EVENT_TOGGLE_CHANGE:
            case NPUI_EVENT_CHECKBOX_CHANGE: {
                uint8_t val = npui_read_u8(&rd);
                add_assoc_bool(result, "value", val);
                break;
            }
            case NPUI_EVENT_SLIDER_CHANGE: {
                float val = npui_read_f32(&rd);
                add_assoc_double(result, "value", val);
                break;
            }
            case NPUI_EVENT_RADIO_CHANGE:
            case NPUI_EVENT_SELECT_CHANGE: {
                uint16_t len;
                const char *text = npui_read_str(&rd, &len);
                add_assoc_stringl(result, "value", text, len);
                break;
            }
            case NPUI_EVENT_SCROLL: {
                float ox = npui_read_f32(&rd);
                float oy = npui_read_f32(&rd);
                float cw = npui_read_f32(&rd);
                float ch = npui_read_f32(&rd);
                add_assoc_double(result, "offset_x", ox);
                add_assoc_double(result, "offset_y", oy);
                add_assoc_double(result, "content_width", cw);
                add_assoc_double(result, "content_height", ch);
                break;
            }
            case NPUI_EVENT_TAB_CHANGE: {
                uint16_t index = npui_read_u16(&rd);
                add_assoc_long(result, "value", index);
                break;
            }
            case NPUI_EVENT_SHEET_DISMISS:
                break;
        }
    }

    atomic_store(&r->event_count, 0);
    atomic_store(&r->event_size, 0);

    pthread_mutex_unlock(&r->event_mutex);
    return 1;
}

/* ══════════════════════════════════════════════════════
 * PHP_FUNCTION Implementations
 * ══════════════════════════════════════════════════════ */

PHP_FUNCTION(nativephp_call)
{
    char *method = NULL;
    size_t method_len;
    char *json_params = NULL;
    size_t json_params_len;

    ZEND_PARSE_PARAMETERS_START(2, 2)
        Z_PARAM_STRING(method, method_len)
        Z_PARAM_STRING(json_params, json_params_len)
    ZEND_PARSE_PARAMETERS_END();

    /* Direct call — same .so, no dlopen/dlsym */
    const char* result = NativePHPCall(method, json_params);

    if (result) {
        RETVAL_STRING(result);
        free((void*)result);
    } else {
        RETURN_NULL();
    }
}

PHP_FUNCTION(nativephp_can)
{
    char *method = NULL;
    size_t method_len;

    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_STRING(method, method_len)
    ZEND_PARSE_PARAMETERS_END();

    /* Direct call — same .so, no dlopen/dlsym */
    int can = NativePHPCan(method);
    RETURN_BOOL(can);
}

PHP_FUNCTION(nativephp_element_init)
{
    ZEND_PARSE_PARAMETERS_NONE();
    RETURN_BOOL(nphp_element_init());
}

PHP_FUNCTION(nativephp_element_publish)
{
    zval *tree;

    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_ARRAY(tree)
    ZEND_PARSE_PARAMETERS_END();

    if (g_element_region == NULL) {
        php_error_docref(NULL, E_WARNING, "Element not initialized. Call nativephp_element_init() first.");
        RETURN_FALSE;
    }

    RETURN_BOOL(nphp_element_publish(tree));
}

PHP_FUNCTION(nativephp_element_wait_event)
{
    zend_long timeout_ms = -1;

    ZEND_PARSE_PARAMETERS_START(0, 1)
        Z_PARAM_OPTIONAL
        Z_PARAM_LONG(timeout_ms)
    ZEND_PARSE_PARAMETERS_END();

    if (g_element_region == NULL) {
        php_error_docref(NULL, E_WARNING, "Element not initialized.");
        RETURN_NULL();
    }

    zval event;
    int rc = nphp_element_wait_event((int)timeout_ms, &event);

    if (rc < 0) RETURN_NULL();
    if (rc == 0) RETURN_NULL();

    RETURN_ZVAL(&event, 0, 0);
}

PHP_FUNCTION(nativephp_element_reset)
{
    ZEND_PARSE_PARAMETERS_NONE();

    if (g_element_region == NULL) RETURN_FALSE;

    nphp_element_reset();
    RETURN_TRUE;
}

PHP_FUNCTION(nativephp_element_shutdown)
{
    ZEND_PARSE_PARAMETERS_NONE();
    nphp_element_shutdown();
}

/* ══════════════════════════════════════════════════════
 * Arginfo (function signatures for Zend)
 * ══════════════════════════════════════════════════════ */

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_nativephp_call, 0, 2, IS_STRING, 1)
    ZEND_ARG_TYPE_INFO(0, method, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, json_params, IS_STRING, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_nativephp_can, 0, 1, _IS_BOOL, 0)
    ZEND_ARG_TYPE_INFO(0, method, IS_STRING, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_nativephp_element_init, 0, 0, _IS_BOOL, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_nativephp_element_publish, 0, 1, _IS_BOOL, 0)
    ZEND_ARG_TYPE_INFO(0, tree, IS_ARRAY, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_nativephp_element_wait_event, 0, 0, IS_ARRAY, 1)
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, timeout_ms, IS_LONG, 0, "-1")
ZEND_END_ARG_INFO()

#define arginfo_nativephp_element_reset arginfo_nativephp_element_init

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_nativephp_element_shutdown, 0, 0, IS_VOID, 0)
ZEND_END_ARG_INFO()

/* ══════════════════════════════════════════════════════
 * Function Entry Table
 *
 * Set as php_embed_module.additional_functions in php_runtime_init().
 * ══════════════════════════════════════════════════════ */

zend_function_entry nativephp_functions[] = {
    ZEND_FE(nativephp_call,                arginfo_nativephp_call)
    ZEND_FE(nativephp_can,                 arginfo_nativephp_can)
    ZEND_FE(nativephp_element_init,        arginfo_nativephp_element_init)
    ZEND_FE(nativephp_element_publish,     arginfo_nativephp_element_publish)
    ZEND_FE(nativephp_element_wait_event,  arginfo_nativephp_element_wait_event)
    ZEND_FE(nativephp_element_reset,       arginfo_nativephp_element_reset)
    ZEND_FE(nativephp_element_shutdown,    arginfo_nativephp_element_shutdown)
    ZEND_FE_END
};
