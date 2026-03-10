cmake_minimum_required(VERSION 3.22.1)
project("com_nativephp_mobile")

set(CMAKE_VERBOSE_MAKEFILE ON)
set(CMAKE_C_FLAGS "${CMAKE_C_FLAGS} -fPIC -fvisibility=default")
set(CMAKE_CXX_FLAGS "${CMAKE_CXX_FLAGS} -std=c++17 -fexceptions -frtti -fPIC -fvisibility=default")
set(CMAKE_SHARED_LINKER_FLAGS "${CMAKE_SHARED_LINKER_FLAGS} -Wl,-z,max-page-size=16384")

set(PHP_LIB_DIR ${CMAKE_CURRENT_SOURCE_DIR}/../staticLibs/arm64-v8a)
set(PHP_INCLUDE_DIR ${CMAKE_CURRENT_SOURCE_DIR}/include/php)

add_definitions(
        -DHAVE_CONFIG_H
        -D__ANDROID__
        -DANDROID
        -D_GNU_SOURCE
        -D_LARGEFILE64_SOURCE
        -D_FILE_OFFSET_BITS=64
        -DHAVE_SYS_PARAM_H
        -DHAVE_BUILD_DEFS_H
        -D_REENTRANT
        -DHAVE_BUILTIN_EXPECT
        -DZTS=1
        -DPTHREADS
)

include_directories(
        ${CMAKE_CURRENT_SOURCE_DIR}/include
        ${CMAKE_CURRENT_SOURCE_DIR}/include/php
        ${CMAKE_CURRENT_SOURCE_DIR}/include/php/main
        ${CMAKE_CURRENT_SOURCE_DIR}/include/php/Zend
        ${CMAKE_CURRENT_SOURCE_DIR}/include/php/TSRM
        ${CMAKE_CURRENT_SOURCE_DIR}/include/php/sapi/embed
        ${CMAKE_CURRENT_SOURCE_DIR}/compat
)

# Import static libraries — only those that exist
set(STATIC_DEP_LIBS "")
foreach(LIB z ssl crypto xml2 sqlite3 iconv charset onig zip curl sodium png16 glob_compat compat icui18n icuuc icudata icuio)
    if(EXISTS ${PHP_LIB_DIR}/lib${LIB}.a)
        add_library(${LIB} STATIC IMPORTED)
        set_target_properties(${LIB} PROPERTIES
            IMPORTED_LOCATION ${PHP_LIB_DIR}/lib${LIB}.a
        )
        list(APPEND STATIC_DEP_LIBS ${LIB})
    else()
        message(STATUS "Skipping lib${LIB}.a (not found)")
    endif()
endforeach()

# PHP must exist
if(NOT EXISTS ${PHP_LIB_DIR}/libphp.a)
    message(FATAL_ERROR "libphp.a not found in ${PHP_LIB_DIR}")
endif()
add_library(php STATIC IMPORTED)
set_target_properties(php PROPERTIES
    IMPORTED_LOCATION ${PHP_LIB_DIR}/libphp.a
)

# Single .so output — everything linked in
add_library(php_wrapper SHARED
        PHP.c
        php_bridge.c
        bridge_jni.cpp
        compat/android_compat.cpp
)

# Link libphp.a with --whole-archive to preserve PHP module registration constructors
target_link_libraries(php_wrapper
        # --whole-archive for libphp.a: PHP uses constructor functions for
        # module registration that would be stripped without this
        -Wl,--whole-archive
        php
        -Wl,--no-whole-archive
        # Dependencies (only referenced symbols pulled in)
        ${STATIC_DEP_LIBS}
        # System
        android log dl
)

# Conditionally link ICU libraries if they exist
foreach(ICU_NAME icui18n icuuc icudata icuio)
    if(EXISTS ${PHP_LIB_DIR}/lib${ICU_NAME}.a)
        target_link_libraries(php_wrapper ${ICU_NAME})
    endif()
endforeach()

if(CMAKE_BUILD_TYPE STREQUAL "Debug")
    add_compile_definitions(DEBUG=1)
    add_compile_options(-g -O0 -fno-limit-debug-info)
endif()
