/*
 * Yoga Layout Bridge — Android (C++ / JNI)
 *
 * Builds Yoga node tree from the flat buffer, computes layout,
 * and returns computed {x, y, w, h} per node as a float array.
 *
 * All Yoga calls stay in C++ — zero JNI overhead per node.
 * Called from the shadow thread after flat buffer parsing.
 */

#include <jni.h>
#include <android/log.h>
#include <cstring>
#include <cstdlib>
#include <vector>
#include <cmath>
#include <yoga/Yoga.h>

#define YOGA_TAG "NativePHP_Yoga"
#define YOGA_LOGI(...) __android_log_print(ANDROID_LOG_INFO, YOGA_TAG, __VA_ARGS__)
#define YOGA_LOGE(...) __android_log_print(ANDROID_LOG_ERROR, YOGA_TAG, __VA_ARGS__)

/* Must match nphp_element.h */
#define FLAT_NODE_SIZE 160

/* Size modes — must match nativephp_ui.h */
#define SIZE_FIXED   0
#define SIZE_WRAP    1
#define SIZE_FILL    2
#define SIZE_PERCENT 3

/* Flex basis modes */
#define BASIS_AUTO    0
#define BASIS_POINTS  1
#define BASIS_PERCENT 2

/* ── Flat node field offsets (packed, little-endian) ── */

struct FlatNodeRef {
    const uint8_t* base;

    uint32_t id()              const { uint32_t v; memcpy(&v, base + 0, 4); return v; }
    uint16_t typeIdx()         const { uint16_t v; memcpy(&v, base + 4, 2); return v; }
    uint16_t childCount()      const { uint16_t v; memcpy(&v, base + 6, 2); return v; }
    uint32_t firstChildOffset() const { uint32_t v; memcpy(&v, base + 8, 4); return v; }

    float    width()           const { float v; memcpy(&v, base + 20, 4); return v; }
    uint8_t  widthMode()       const { return base[24]; }
    float    height()          const { float v; memcpy(&v, base + 25, 4); return v; }
    uint8_t  heightMode()      const { return base[29]; }

    float    paddingTop()      const { float v; memcpy(&v, base + 30, 4); return v; }
    float    paddingRight()    const { float v; memcpy(&v, base + 34, 4); return v; }
    float    paddingBottom()   const { float v; memcpy(&v, base + 38, 4); return v; }
    float    paddingLeft()     const { float v; memcpy(&v, base + 42, 4); return v; }

    float    marginTop()       const { float v; memcpy(&v, base + 46, 4); return v; }
    float    marginRight()     const { float v; memcpy(&v, base + 50, 4); return v; }
    float    marginBottom()    const { float v; memcpy(&v, base + 54, 4); return v; }
    float    marginLeft()      const { float v; memcpy(&v, base + 58, 4); return v; }

    float    flexGrow()        const { float v; memcpy(&v, base + 62, 4); return v; }
    float    flexShrink()      const { float v; memcpy(&v, base + 66, 4); return v; }
    uint8_t  alignSelf()       const { return base[70]; }
    uint8_t  alignItems()      const { return base[71]; }
    uint8_t  justifyContent()  const { return base[72]; }
    float    gap()             const { float v; memcpy(&v, base + 73, 4); return v; }

    /* Extended layout (Yoga) */
    float    minWidth()        const { float v; memcpy(&v, base + 78, 4); return v; }
    float    minHeight()       const { float v; memcpy(&v, base + 82, 4); return v; }
    float    maxWidth()        const { float v; memcpy(&v, base + 86, 4); return v; }
    float    maxHeight()       const { float v; memcpy(&v, base + 90, 4); return v; }
    float    flexBasis()       const { float v; memcpy(&v, base + 94, 4); return v; }
    uint8_t  flexBasisMode()   const { return base[98]; }
    uint8_t  flexWrap()        const { return base[99]; }
    uint8_t  flexDirection()   const { return base[100]; }
    uint8_t  positionType()    const { return base[101]; }
    float    posTop()          const { float v; memcpy(&v, base + 102, 4); return v; }
    float    posRight()        const { float v; memcpy(&v, base + 106, 4); return v; }
    float    posBottom()       const { float v; memcpy(&v, base + 110, 4); return v; }
    float    posLeft()         const { float v; memcpy(&v, base + 114, 4); return v; }
    uint8_t  display()         const { return base[118]; }
    uint8_t  overflow()        const { return base[119]; }
    uint8_t  alignContent()    const { return base[120]; }
    uint8_t  direction()       const { return base[121]; }
    float    aspectRatio()     const { float v; memcpy(&v, base + 122, 4); return v; }
    float    rowGap()          const { float v; memcpy(&v, base + 126, 4); return v; }
};

/* ── Yoga enum mapping ── */

static YGAlign mapAlign(uint8_t v) {
    switch (v) {
        case 1: return YGAlignCenter;
        case 2: return YGAlignFlexEnd;
        case 3: return YGAlignStretch;
        case 4: return YGAlignBaseline;
        case 5: return YGAlignSpaceBetween;
        case 6: return YGAlignSpaceAround;
        case 7: return YGAlignSpaceEvenly;
        default: return YGAlignFlexStart;
    }
}

static YGJustify mapJustify(uint8_t v) {
    switch (v) {
        case 1: return YGJustifyCenter;
        case 2: return YGJustifyFlexEnd;
        case 3: return YGJustifySpaceBetween;
        case 4: return YGJustifySpaceAround;
        case 5: return YGJustifySpaceEvenly;
        default: return YGJustifyFlexStart;
    }
}

static YGFlexDirection mapFlexDirection(uint8_t v) {
    switch (v) {
        case 1: return YGFlexDirectionRow;
        case 2: return YGFlexDirectionColumnReverse;
        case 3: return YGFlexDirectionRowReverse;
        default: return YGFlexDirectionColumn;
    }
}

static YGWrap mapWrap(uint8_t v) {
    switch (v) {
        case 1: return YGWrapWrap;
        case 2: return YGWrapWrapReverse;
        default: return YGWrapNoWrap;
    }
}

static YGPositionType mapPositionType(uint8_t v) {
    switch (v) {
        case 1: return YGPositionTypeAbsolute;
        case 2: return YGPositionTypeStatic;
        default: return YGPositionTypeRelative;
    }
}

static YGOverflow mapOverflow(uint8_t v) {
    switch (v) {
        case 1: return YGOverflowHidden;
        case 2: return YGOverflowScroll;
        default: return YGOverflowVisible;
    }
}

static YGDirection mapDirection(uint8_t v) {
    switch (v) {
        case 1: return YGDirectionLTR;
        case 2: return YGDirectionRTL;
        default: return YGDirectionInherit;
    }
}

/* ── Apply flat node to Yoga node ── */

static void applyNodeStyle(YGNodeRef ygNode, const FlatNodeRef& fn, const char* type) {
    /* Determine flex direction from element type if not explicitly set */
    uint8_t fd = fn.flexDirection();
    if (fd == 0) {
        /* Default: "row" type → Row, everything else → Column */
        if (type && strcmp(type, "row") == 0) {
            YGNodeStyleSetFlexDirection(ygNode, YGFlexDirectionRow);
        } else {
            YGNodeStyleSetFlexDirection(ygNode, YGFlexDirectionColumn);
        }
    } else {
        YGNodeStyleSetFlexDirection(ygNode, mapFlexDirection(fd));
    }

    /* Width/Height */
    switch (fn.widthMode()) {
        case SIZE_FIXED:
            if (fn.width() > 0) YGNodeStyleSetWidth(ygNode, fn.width());
            break;
        case SIZE_FILL:
            YGNodeStyleSetWidthPercent(ygNode, 100.0f);
            break;
        case SIZE_PERCENT:
            YGNodeStyleSetWidthPercent(ygNode, fn.width());
            break;
        default: /* WRAP — Yoga auto (don't set) */
            break;
    }

    switch (fn.heightMode()) {
        case SIZE_FIXED:
            if (fn.height() > 0) YGNodeStyleSetHeight(ygNode, fn.height());
            break;
        case SIZE_FILL:
            YGNodeStyleSetHeightPercent(ygNode, 100.0f);
            break;
        case SIZE_PERCENT:
            YGNodeStyleSetHeightPercent(ygNode, fn.height());
            break;
        default:
            break;
    }

    /* Padding */
    if (fn.paddingTop() > 0)    YGNodeStyleSetPadding(ygNode, YGEdgeTop, fn.paddingTop());
    if (fn.paddingRight() > 0)  YGNodeStyleSetPadding(ygNode, YGEdgeRight, fn.paddingRight());
    if (fn.paddingBottom() > 0) YGNodeStyleSetPadding(ygNode, YGEdgeBottom, fn.paddingBottom());
    if (fn.paddingLeft() > 0)   YGNodeStyleSetPadding(ygNode, YGEdgeLeft, fn.paddingLeft());

    /* Margin */
    if (fn.marginTop() > 0)    YGNodeStyleSetMargin(ygNode, YGEdgeTop, fn.marginTop());
    if (fn.marginRight() > 0)  YGNodeStyleSetMargin(ygNode, YGEdgeRight, fn.marginRight());
    if (fn.marginBottom() > 0) YGNodeStyleSetMargin(ygNode, YGEdgeBottom, fn.marginBottom());
    if (fn.marginLeft() > 0)   YGNodeStyleSetMargin(ygNode, YGEdgeLeft, fn.marginLeft());

    /* Flex */
    if (fn.flexGrow() > 0)   YGNodeStyleSetFlexGrow(ygNode, fn.flexGrow());
    if (fn.flexShrink() != 1.0f) YGNodeStyleSetFlexShrink(ygNode, fn.flexShrink());

    /* Alignment */
    if (fn.alignSelf() > 0)      YGNodeStyleSetAlignSelf(ygNode, mapAlign(fn.alignSelf()));
    if (fn.alignItems() > 0)     YGNodeStyleSetAlignItems(ygNode, mapAlign(fn.alignItems()));
    if (fn.justifyContent() > 0) YGNodeStyleSetJustifyContent(ygNode, mapJustify(fn.justifyContent()));

    /* Gap */
    if (fn.gap() > 0) YGNodeStyleSetGap(ygNode, YGGutterAll, fn.gap());

    /* Extended layout fields */
    if (fn.minWidth() > 0)  YGNodeStyleSetMinWidth(ygNode, fn.minWidth());
    if (fn.minHeight() > 0) YGNodeStyleSetMinHeight(ygNode, fn.minHeight());
    if (fn.maxWidth() > 0)  YGNodeStyleSetMaxWidth(ygNode, fn.maxWidth());
    if (fn.maxHeight() > 0) YGNodeStyleSetMaxHeight(ygNode, fn.maxHeight());

    /* Flex basis */
    switch (fn.flexBasisMode()) {
        case BASIS_POINTS:
            YGNodeStyleSetFlexBasis(ygNode, fn.flexBasis());
            break;
        case BASIS_PERCENT:
            YGNodeStyleSetFlexBasisPercent(ygNode, fn.flexBasis());
            break;
        default: /* auto */
            YGNodeStyleSetFlexBasisAuto(ygNode);
            break;
    }

    /* Wrap */
    if (fn.flexWrap() > 0) YGNodeStyleSetFlexWrap(ygNode, mapWrap(fn.flexWrap()));

    /* Position type */
    if (fn.positionType() > 0) YGNodeStyleSetPositionType(ygNode, mapPositionType(fn.positionType()));

    /* Position TRBL */
    if (fn.posTop() != 0)    YGNodeStyleSetPosition(ygNode, YGEdgeTop, fn.posTop());
    if (fn.posRight() != 0)  YGNodeStyleSetPosition(ygNode, YGEdgeRight, fn.posRight());
    if (fn.posBottom() != 0) YGNodeStyleSetPosition(ygNode, YGEdgeBottom, fn.posBottom());
    if (fn.posLeft() != 0)   YGNodeStyleSetPosition(ygNode, YGEdgeLeft, fn.posLeft());

    /* Display */
    if (fn.display() == 1) YGNodeStyleSetDisplay(ygNode, YGDisplayNone);

    /* Overflow */
    if (fn.overflow() > 0) YGNodeStyleSetOverflow(ygNode, mapOverflow(fn.overflow()));

    /* Align content */
    if (fn.alignContent() > 0) YGNodeStyleSetAlignContent(ygNode, mapAlign(fn.alignContent()));

    /* Direction */
    if (fn.direction() > 0) YGNodeStyleSetDirection(ygNode, mapDirection(fn.direction()));

    /* Aspect ratio */
    if (fn.aspectRatio() > 0) YGNodeStyleSetAspectRatio(ygNode, fn.aspectRatio());

    /* Row gap */
    if (fn.rowGap() > 0) YGNodeStyleSetGap(ygNode, YGGutterRow, fn.rowGap());
}

/* ── Measure function for leaf nodes with pre-measured intrinsic sizes ── */

struct MeasureContext {
    float intrinsicWidth;
    float intrinsicHeight;
};

static YGSize leafMeasureFunc(
    YGNodeConstRef node,
    float width, YGMeasureMode widthMode,
    float height, YGMeasureMode heightMode
) {
    auto* ctx = static_cast<MeasureContext*>(YGNodeGetContext(node));
    YGSize size;

    switch (widthMode) {
        case YGMeasureModeExactly:   size.width = width; break;
        case YGMeasureModeAtMost:    size.width = (ctx->intrinsicWidth > 0 && ctx->intrinsicWidth < width) ? ctx->intrinsicWidth : width; break;
        default:                     size.width = ctx->intrinsicWidth; break;
    }

    switch (heightMode) {
        case YGMeasureModeExactly:   size.height = height; break;
        case YGMeasureModeAtMost:    size.height = (ctx->intrinsicHeight > 0 && ctx->intrinsicHeight < height) ? ctx->intrinsicHeight : height; break;
        default:                     size.height = ctx->intrinsicHeight; break;
    }

    /* Text wrapping: if width was constrained below intrinsic width,
       the content will wrap — scale height proportionally.
       Only triggers for text-like nodes where intrinsicWidth > constrained width. */
    if (heightMode != YGMeasureModeExactly &&
        ctx->intrinsicWidth > 0 && ctx->intrinsicHeight > 0 &&
        size.width < ctx->intrinsicWidth) {
        float ratio = ceilf(ctx->intrinsicWidth / size.width);
        size.height = ctx->intrinsicHeight * ratio;
    }

    return size;
}

/* ── Build Yoga tree from flat buffer (DFS pre-order) ── */

struct ScrollNodeInfo {
    YGNodeRef node;
    int dfsIndex;
};

struct YogaTreeBuilder {
    const uint8_t* flatBuf;
    int flatSize;
    const char* const* typeTable;
    int typeCount;
    const float* measurements;
    int offset;
    int nodeIndex;
    std::vector<YGNodeRef> allNodes;
    std::vector<MeasureContext*> measureContexts;
    std::vector<ScrollNodeInfo> scrollNodes;

    YGNodeRef buildNode() {
        if (offset + FLAT_NODE_SIZE > flatSize) return nullptr;

        FlatNodeRef fn = { flatBuf + offset };
        offset += FLAT_NODE_SIZE;
        int myIndex = nodeIndex++;

        uint16_t typeIdx = fn.typeIdx();
        const char* type = (typeIdx < typeCount) ? typeTable[typeIdx] : "column";

        YGNodeRef ygNode = YGNodeNew();
        allNodes.push_back(ygNode);

        applyNodeStyle(ygNode, fn, type);

        // Two-pass scroll layout: in pass 1, use overflow:hidden so the node
        // can shrink below content size (flex_grow works). Pass 2 re-layouts
        // children with overflow:scroll + unlimited space on the scroll axis.
        if (fn.overflow() == 2) {
            YGNodeStyleSetOverflow(ygNode, YGOverflowHidden);
            scrollNodes.push_back({ygNode, myIndex});
        }

        uint16_t childCount = fn.childCount();

        if (childCount == 0 && measurements) {
            float mw = measurements[myIndex * 2];
            float mh = measurements[myIndex * 2 + 1];
            if (mw > 0 || mh > 0) {
                auto* ctx = new MeasureContext{mw, mh};
                measureContexts.push_back(ctx);
                YGNodeSetContext(ygNode, ctx);
                YGNodeSetMeasureFunc(ygNode, leafMeasureFunc);
            }
        }

        bool isStack = type && strcmp(type, "stack") == 0;

        for (uint16_t i = 0; i < childCount; i++) {
            YGNodeRef child = buildNode();
            if (child) {
                if (isStack) {
                    YGNodeStyleSetPositionType(child, YGPositionTypeAbsolute);
                    if (YGNodeStyleGetWidth(child).unit == YGUnitUndefined) {
                        YGNodeStyleSetWidthPercent(child, 100.0f);
                    }
                    if (YGNodeStyleGetHeight(child).unit == YGUnitUndefined) {
                        YGNodeStyleSetHeightPercent(child, 100.0f);
                    }
                }
                YGNodeInsertChild(ygNode, child, i);
            }
        }

        return ygNode;
    }

    void freeAll() {
        for (auto node : allNodes) {
            YGNodeFree(node);
        }
        allNodes.clear();
        for (auto ctx : measureContexts) {
            delete ctx;
        }
        measureContexts.clear();
    }
};

/* ── Collect computed layout (DFS pre-order) ── */

static void collectLayout(YGNodeRef node, float* out, int& idx, int maxNodes) {
    if (idx >= maxNodes) return;

    float left = YGNodeLayoutGetLeft(node);
    float top = YGNodeLayoutGetTop(node);
    float width = YGNodeLayoutGetWidth(node);
    float height = YGNodeLayoutGetHeight(node);

    out[idx * 4 + 0] = left;
    out[idx * 4 + 1] = top;
    out[idx * 4 + 2] = width;
    out[idx * 4 + 3] = height;
    idx++;

    int childCount = YGNodeGetChildCount(node);
    for (int i = 0; i < childCount; i++) {
        collectLayout(YGNodeGetChild(node, i), out, idx, maxNodes);
    }
}

/* ── JNI Entry Point ── */

extern "C"
JNIEXPORT jfloatArray JNICALL
Java_com_nativephp_mobile_ui_nativerender_YogaBridge_nativeComputeLayout(
    JNIEnv* env, jclass /*clazz*/,
    jbyteArray flatBufferArr, jint nodeCount,
    jfloat viewportWidth, jfloat viewportHeight,
    jfloat density,
    jobjectArray typeTableArr,
    jfloatArray measurementsArr
) {
    if (nodeCount <= 0) return nullptr;

    /* Get flat buffer bytes */
    jsize flatLen = env->GetArrayLength(flatBufferArr);
    jbyte* flatBytes = env->GetByteArrayElements(flatBufferArr, nullptr);
    if (!flatBytes) return nullptr;

    /* Get type table */
    int typeCount = env->GetArrayLength(typeTableArr);
    std::vector<const char*> typeStrs(typeCount);
    std::vector<jstring> typeJStrs(typeCount);
    for (int i = 0; i < typeCount; i++) {
        typeJStrs[i] = (jstring)env->GetObjectArrayElement(typeTableArr, i);
        typeStrs[i] = env->GetStringUTFChars(typeJStrs[i], nullptr);
    }

    /* Get measurements array (may be null) */
    float* measurements = nullptr;
    if (measurementsArr != nullptr) {
        measurements = env->GetFloatArrayElements(measurementsArr, nullptr);
    }

    /* Build Yoga tree */
    YogaTreeBuilder builder;
    builder.flatBuf = reinterpret_cast<const uint8_t*>(flatBytes);
    builder.flatSize = flatLen;
    builder.typeTable = typeStrs.data();
    builder.typeCount = typeCount;
    builder.measurements = measurements;
    builder.offset = 0;
    builder.nodeIndex = 0;

    YGNodeRef root = builder.buildNode();

    env->ReleaseByteArrayElements(flatBufferArr, flatBytes, JNI_ABORT);

    if (!root) {
        for (int i = 0; i < typeCount; i++) {
            env->ReleaseStringUTFChars(typeJStrs[i], typeStrs[i]);
        }
        builder.freeAll();
        return nullptr;
    }

    /* Root node always fills the viewport */
    YGNodeStyleSetWidth(root, viewportWidth / density);
    YGNodeStyleSetHeight(root, viewportHeight / density);

    /* Pass 1: layout with scroll nodes using overflow:visible (flex_grow works) */
    YGNodeCalculateLayout(root, viewportWidth / density, viewportHeight / density, YGDirectionLTR);

    /* Collect results — 4 floats per node (x, y, w, h) */
    int maxNodes = nodeCount;
    std::vector<float> results(maxNodes * 4, 0.0f);
    int idx = 0;
    collectLayout(root, results.data(), idx, maxNodes);

    /* Pass 2: re-layout scroll subtrees with unlimited space on the scroll axis
       so children get their natural sizes (enables actual scrolling content) */
    for (auto& info : builder.scrollNodes) {
        float w = YGNodeLayoutGetWidth(info.node);
        float h = YGNodeLayoutGetHeight(info.node);

        YGFlexDirection dir = YGNodeStyleGetFlexDirection(info.node);
        bool horizontal = (dir == YGFlexDirectionRow || dir == YGFlexDirectionRowReverse);

        YGNodeStyleSetOverflow(info.node, YGOverflowScroll);
        YGNodeCalculateLayout(info.node,
            horizontal ? YGUndefined : w,
            horizontal ? h : YGUndefined,
            YGDirectionLTR);

        // Re-collect children layouts (scroll node itself keeps pass-1 position)
        int childIdx = info.dfsIndex + 1;
        int childCount = YGNodeGetChildCount(info.node);
        for (int i = 0; i < childCount; i++) {
            collectLayout(YGNodeGetChild(info.node, i), results.data(), childIdx, maxNodes);
        }
    }

    /* Cleanup Yoga nodes */
    builder.freeAll();

    /* Release measurements */
    if (measurements) {
        env->ReleaseFloatArrayElements(measurementsArr, measurements, JNI_ABORT);
    }

    /* Release type table strings */
    for (int i = 0; i < typeCount; i++) {
        env->ReleaseStringUTFChars(typeJStrs[i], typeStrs[i]);
    }

    /* Return float array to Kotlin */
    int resultCount = idx * 4;
    jfloatArray result = env->NewFloatArray(resultCount);
    env->SetFloatArrayRegion(result, 0, resultCount, results.data());

    YOGA_LOGI("computeLayout: nodes=%d viewport=%.0fx%.0f density=%.1f", nodeCount, viewportWidth, viewportHeight, density);

    return result;
}
