package com.nativephp.mobile.ui

import android.util.Log
import com.google.gson.Gson
import com.google.gson.JsonElement
import com.google.gson.JsonParser
import com.google.gson.annotations.SerializedName
import org.json.JSONArray
import org.json.JSONObject

/**
 * Base component structure
 * JSON example: [{"type":"bottom_nav","data":{...}}]
 */
data class NativeComponent(
    val type: String,
    val data: JsonElement  // Using JsonElement for flexible parsing
)

/**
 * Bottom nav specific data
 */
data class BottomNavData(
    val dark: Boolean? = null,
    @SerializedName("label_visibility")
    val labelVisibility: String? = "labeled",
    @SerializedName("active_color")
    val activeColor: String? = null,
    val children: List<BottomNavItemComponent>? = null
)

/**
 * Bottom nav item as a component (wraps BottomNavItem data)
 */
data class BottomNavItemComponent(
    val type: String,
    val data: BottomNavItem
)

/**
 * Bottom navigation item data
 */
data class BottomNavItem(
    val id: String,
    val label: String,
    val url: String,
    val icon: String,
    val active: Boolean? = false,
    val badge: String? = null,
    @SerializedName("badge_color")
    val badgeColor: String? = null,
    val news: Boolean? = false
)

/**
 * Side nav specific data
 */
data class SideNavData(
    val dark: Boolean? = null,
    @SerializedName("label_visibility")
    val labelVisibility: String? = "labeled",
    @SerializedName("gestures_enabled")
    val gesturesEnabled: Boolean? = false,
    val children: List<SideNavChild>? = null
)

/**
 * Side nav child component - can be an item, group, or divider
 */
data class SideNavChild(
    val type: String,
    val data: JsonElement?  // Nullable to support dividers with no data
)

/**
 * Side navigation item data
 */
data class SideNavItem(
    val id: String,
    val label: String,
    val url: String,
    val icon: String,
    val active: Boolean? = false,
    val badge: String? = null,
    @SerializedName("badge_color")
    val badgeColor: String? = null,
    @SerializedName("open_in_browser")
    val openInBrowser: Boolean? = null  // If true, opens URL in external browser
)

/**
 * Side navigation group data (expandable)
 */
data class SideNavGroup(
    val heading: String,
    val icon: String? = null,
    val expanded: Boolean? = false,
    val children: List<SideNavGroupChild>? = null
)

/**
 * Side nav group child - items within an expandable group
 */
data class SideNavGroupChild(
    val type: String,  // "side_nav_item"
    val data: JsonElement  // Needs to be parsed separately
)

/**
 * Side nav header data
 */
data class SideNavHeader(
    val title: String? = null,
    val subtitle: String? = null,
    val icon: String? = null,
    @SerializedName("background_color")
    val backgroundColor: String? = null,
    @SerializedName("image_url")
    val imageUrl: String? = null,
    val event: String? = null,
    @SerializedName("show_close_button")
    val showCloseButton: Boolean? = true,
    val pinned: Boolean? = false
)

/**
 * Top bar (AppBar) specific data
 */
data class TopBarData(
    val title: String,
    val subtitle: String? = null,
    @SerializedName("show_navigation_icon")
    val showNavigationIcon: Boolean? = true,
    @SerializedName("background_color")
    val backgroundColor: String? = null,
    @SerializedName("text_color")
    val textColor: String? = null,
    val elevation: Int? = null,
    val children: List<TopBarActionComponent>? = null
)

/**
 * Top bar action as a component (wraps TopBarActionData)
 */
data class TopBarActionComponent(
    val type: String,
    val data: TopBarActionData
)

/**
 * Universal data class to support both standard Actions and nested Sections.
 * Properties not relevant to a specific component type (like 'title' for actions) simply decode as null.
 */
data class TopBarActionData(
    val id: String? = null,
    val icon: String? = null,
    val label: String? = null,
    val subtitle: String? = null,
    val title: String? = null, // Used primarily by sections
    val url: String? = null,
    val event: String? = null,
    val role: String? = null, // Parsed but safely ignored by UI as requested
    val children: List<TopBarActionComponent>? = null
)

/**
 * FAB (Floating Action Button) specific data
 */
data class FabData(
    val label: String? = null,
    val icon: String,
    val url: String? = null,
    val event: String? = null,
    val size: String? = "regular",
    val position: String? = "end",
    @SerializedName("bottom_offset")
    val bottomOffset: Int? = null,
    val elevation: Int? = null,
    @SerializedName("corner_radius")
    val cornerRadius: Int? = null,
    @SerializedName("container_color")
    val containerColor: String? = null,
    @SerializedName("content_color")
    val contentColor: String? = null
)

/**
 * Helper to parse NativeUI JSON
 */
object NativeUIParser {
    private val gson = Gson()

    fun parse(json: String): List<NativeComponent> {
        return try {
            gson.fromJson(json, Array<NativeComponent>::class.java).toList()
        } catch (e: Exception) {
            emptyList()
        }
    }

    fun parseFromObject(obj: Any): List<NativeComponent> {
        return try {
            Log.d("NativeUIParser", "parseFromObject called with type: ${obj.javaClass.name}")

            val jsonTree = when (obj) {
                is JSONArray -> {
                    Log.d("NativeUIParser", "Converting JSONArray: ${obj.toString()}")
                    JsonParser.parseString(obj.toString())
                }
                is JSONObject -> {
                    Log.d("NativeUIParser", "Converting JSONObject: ${obj.toString()}")
                    JsonParser.parseString(obj.toString())
                }
                else -> {
                    Log.d("NativeUIParser", "Using toJsonTree for: ${obj.javaClass.name}")
                    gson.toJsonTree(obj)
                }
            }

            val components = gson.fromJson(jsonTree, Array<NativeComponent>::class.java).toList()
            Log.d("NativeUIParser", "✅ Successfully parsed ${components.size} components")
            components
        } catch (e: Exception) {
            Log.e("NativeUIParser", "❌ Failed to parse components from object: ${e.message}", e)
            Log.e("NativeUIParser", "Object type: ${obj.javaClass.name}")
            emptyList()
        }
    }

    fun parseBottomNavData(data: JsonElement): BottomNavData? {
        return try {
            gson.fromJson(data, BottomNavData::class.java)
        } catch (e: Exception) {
            null
        }
    }

    fun parseSideNavData(data: JsonElement): SideNavData? {
        return try {
            gson.fromJson(data, SideNavData::class.java)
        } catch (e: Exception) {
            null
        }
    }

    fun parseFabData(data: JsonElement): FabData? {
        return try {
            gson.fromJson(data, FabData::class.java)
        } catch (e: Exception) {
            null
        }
    }

    fun parseSideNavItem(data: JsonElement?): SideNavItem? {
        if (data == null) return null
        return try {
            gson.fromJson(data, SideNavItem::class.java)
        } catch (e: Exception) {
            null
        }
    }

    fun parseSideNavGroup(data: JsonElement?): SideNavGroup? {
        if (data == null) return null
        return try {
            gson.fromJson(data, SideNavGroup::class.java)
        } catch (e: Exception) {
            null
        }
    }

    fun parseSideNavHeader(data: JsonElement?): SideNavHeader? {
        if (data == null) return null
        return try {
            gson.fromJson(data, SideNavHeader::class.java)
        } catch (e: Exception) {
            null
        }
    }

    fun parseTopBarData(data: JsonElement): TopBarData? {
        return try {
            gson.fromJson(data, TopBarData::class.java)
        } catch (e: Exception) {
            null
        }
    }
}