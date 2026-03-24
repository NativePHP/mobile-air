// android_compat.cpp
#include "android_compat.h"
#include <cstdio>
#include <cstring>
#include <cstdlib>
#include <dirent.h>
#include <fnmatch.h>
#include <errno.h>
#include <unistd.h>
#include <sys/resource.h>
#include <syscall.h>
#include <android/log.h>
#include <glob.h>

#define LOGI(...) __android_log_print(ANDROID_LOG_INFO, "Compat", __VA_ARGS__)


__attribute__((visibility("default")))
extern "C" int getdtablesize(void) {
    __android_log_print(ANDROID_LOG_INFO, "Compat", "getdtablesize called");
    struct rlimit rlim;
    if (getrlimit(RLIMIT_NOFILE, &rlim) == 0) {
        return rlim.rlim_cur;
    }
    return 1024;
}

__attribute__((visibility("default")))
extern "C" ssize_t copy_file_range(int fd_in, off64_t *off_in,
                                   int fd_out, off64_t *off_out,
                                   size_t len, unsigned int flags) {
    return syscall(__NR_copy_file_range, fd_in, off_in,
                   fd_out, off_out, len, flags);
}

__attribute__((visibility("default")))
extern "C" char* nl_langinfo(int item) {
    // Android bionic doesn't provide nl_langinfo; return empty string
    return (char*)"";
}

__attribute__((visibility("default")))
extern "C" char* ctermid(char* s) {
    static char buf[] = "/dev/null";
    if (s) {
        snprintf(s, L_ctermid, "%s", buf);
        return s;
    }
    return buf;
}

// glob/globfree for Android API < 28 (bionic doesn't provide them)
#if __ANDROID_API__ < 28
__attribute__((visibility("default")))
extern "C" int glob(const char *pattern, int flags,
                    int (*errfunc)(const char *epath, int eerrno),
                    glob_t *pglob)
{
    const char *dir_end;
    const char *base_pattern;
    char dir_path[4096];
    DIR *dir;
    struct dirent *entry;
    size_t count = 0;
    size_t capacity = 32;

    if (!pglob) return GLOB_NOMATCH;

    if (!(flags & GLOB_APPEND)) {
        pglob->gl_pathc = 0;
        pglob->gl_pathv = NULL;
    }

    dir_end = strrchr(pattern, '/');
    if (dir_end) {
        size_t dir_len = dir_end - pattern;
        if (dir_len >= sizeof(dir_path)) return GLOB_NOMATCH;
        memcpy(dir_path, pattern, dir_len);
        dir_path[dir_len] = '\0';
        base_pattern = dir_end + 1;
    } else {
        strcpy(dir_path, ".");
        base_pattern = pattern;
    }

    dir = opendir(dir_path);
    if (!dir) {
        if (errfunc && errfunc(dir_path, errno))
            return GLOB_ABORTED;
        return GLOB_NOMATCH;
    }

    pglob->gl_pathv = (char**)malloc(capacity * sizeof(char *));
    if (!pglob->gl_pathv) {
        closedir(dir);
        return GLOB_NOSPACE;
    }

    while ((entry = readdir(dir)) != NULL) {
        if (fnmatch(base_pattern, entry->d_name, 0) == 0) {
            if (count + 1 >= capacity) {
                capacity *= 2;
                char **new_pathv = (char**)realloc(pglob->gl_pathv, capacity * sizeof(char *));
                if (!new_pathv) {
                    closedir(dir);
                    return GLOB_NOSPACE;
                }
                pglob->gl_pathv = new_pathv;
            }

            size_t path_len = strlen(dir_path) + 1 + strlen(entry->d_name) + 1;
            pglob->gl_pathv[count] = (char*)malloc(path_len);
            if (!pglob->gl_pathv[count]) {
                closedir(dir);
                return GLOB_NOSPACE;
            }

            if (strcmp(dir_path, ".") == 0) {
                strcpy(pglob->gl_pathv[count], entry->d_name);
            } else {
                snprintf(pglob->gl_pathv[count], path_len, "%s/%s", dir_path, entry->d_name);
            }
            count++;
        }
    }

    closedir(dir);
    pglob->gl_pathc = count;
    pglob->gl_pathv[count] = NULL;

    if (count == 0) {
        free(pglob->gl_pathv);
        pglob->gl_pathv = NULL;
        return GLOB_NOMATCH;
    }

    return 0;
}

__attribute__((visibility("default")))
extern "C" void globfree(glob_t *pglob)
{
    size_t i;
    if (!pglob || !pglob->gl_pathv) return;
    for (i = 0; i < pglob->gl_pathc; i++) {
        free(pglob->gl_pathv[i]);
    }
    free(pglob->gl_pathv);
    pglob->gl_pathv = NULL;
    pglob->gl_pathc = 0;
}
#endif /* __ANDROID_API__ < 28 */