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

# Import PHP as a static library
add_library(php STATIC IMPORTED)
set_target_properties(php PROPERTIES IMPORTED_LOCATION ${PHP_LIB_DIR}/libphp.a)

# Import static dependencies (always present)
foreach(DEP_NAME z ssl crypto xml2 sqlite3 iconv charset onig curl sodium png16 glob_compat compat)
    add_library(${DEP_NAME} STATIC IMPORTED)
    set_target_properties(${DEP_NAME} PROPERTIES IMPORTED_LOCATION ${PHP_LIB_DIR}/lib${DEP_NAME}.a)
endforeach()

# Import ICU static libraries (optional — only present in ICU builds)
foreach(ICU_NAME icui18n icuuc icudata icuio)
    if(EXISTS ${PHP_LIB_DIR}/lib${ICU_NAME}.a)
        add_library(${ICU_NAME} STATIC IMPORTED)
        set_target_properties(${ICU_NAME} PROPERTIES IMPORTED_LOCATION ${PHP_LIB_DIR}/lib${ICU_NAME}.a)
    endif()
endforeach()

# PHP wrapper — single shared library output
add_library(php_wrapper SHARED
        PHP.c
        php_bridge.c
        libphp_wrapper.cpp
        bridge_jni.cpp
        ${CMAKE_CURRENT_SOURCE_DIR}/compat/android_compat.cpp
)

target_include_directories(php_wrapper PUBLIC
        ${CMAKE_CURRENT_SOURCE_DIR}/include
        ${CMAKE_CURRENT_SOURCE_DIR}/include/php
        ${CMAKE_CURRENT_SOURCE_DIR}/include/php/main
        ${CMAKE_CURRENT_SOURCE_DIR}/include/php/Zend
        ${CMAKE_CURRENT_SOURCE_DIR}/include/php/TSRM
)

# Link libphp.a with --whole-archive to preserve PHP module registration constructors
target_link_libraries(php_wrapper
        -Wl,--whole-archive
        php
        -Wl,--no-whole-archive
        z ssl crypto xml2 sqlite3 iconv charset onig curl sodium png16 glob_compat compat
        android
        log
        dl
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
