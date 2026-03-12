/*
 * Yoga Layout Bridge — iOS (C)
 *
 * C interface callable from Swift. Builds Yoga node tree from flat buffer,
 * computes layout, and returns {x, y, w, h} per node.
 */

#ifndef YOGA_BRIDGE_H
#define YOGA_BRIDGE_H

#include <stdint.h>
#include <stddef.h>

#ifdef __cplusplus
extern "C" {
#endif

/**
 * Computed layout result for a single node.
 */
typedef struct {
    float x;
    float y;
    float width;
    float height;
} YogaComputedLayout;

/**
 * Compute Yoga layout from a flat buffer of packed nodes.
 *
 * @param flatBuffer     Pointer to flat node buffer (160 bytes per node, DFS pre-order)
 * @param flatBufferSize Total size in bytes
 * @param nodeCount      Number of nodes
 * @param viewportWidth  Screen width in points
 * @param viewportHeight Screen height in points
 * @param typeTable      Array of interned type strings (C strings)
 * @param typeCount      Number of types in typeTable
 * @param measurements   Pre-measured intrinsic sizes: nodeCount*2 floats [w0,h0,w1,h1,...].
 *                       0,0 means no measurement (container node). May be NULL.
 * @param outLayouts     Pre-allocated array of YogaComputedLayout[nodeCount]
 * @return Number of nodes with computed layout, or 0 on error
 */
int yoga_compute_layout(
    const uint8_t* flatBuffer,
    size_t flatBufferSize,
    int nodeCount,
    float viewportWidth,
    float viewportHeight,
    const char* const* typeTable,
    int typeCount,
    const float* measurements,
    YogaComputedLayout* outLayouts
);

#ifdef __cplusplus
}
#endif

#endif /* YOGA_BRIDGE_H */
