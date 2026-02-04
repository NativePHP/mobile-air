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
 * For items: type="side_nav_item", data contains SideNavItem
 * For groups: type="side_nav_group", data contains SideNavGroup
 * For dividers: type="horizontal_divider", data is null/empty
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
 * Top bar action as a component (wraps TopBarAction data)
 */
data class TopBarActionComponent(
    val type: String,
    val data: TopBarAction
)

/**
 * Top bar action item data
 */
data class TopBarAction(
    val id: String,
    val icon: String,
    val label: String? = null,
    val url: String? = null,
    val event: String? = null
)

/**
 * FAB (Floating Action Button) specific data
 */
data class FabData(
    val label: String? = null,
    val icon: String,
    val url: String? = null,
    val event: String? = null,
    val size: String? = "regular",  // "small", "regular", "large", "extended"
    val position: String? = "end",  // "end", "center", "start"
    @SerializedName("bottom_offset")
    val bottomOffset: Int? = null,  // Offset from bottom in dp
    val elevation: Int? = null,  // Elevation in dp
    @SerializedName("corner_radius")
    val cornerRadius: Int? = null,  // Corner radius in dp (default: circular)
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

            // Convert the object to JsonElement
            // Handle org.json types (from bridge) separately from Gson types
            val jsonTree = when (obj) {
                is JSONArray -> {
                    // Convert org.json.JSONArray to Gson JsonElement
                    Log.d("NativeUIParser", "Converting JSONArray: ${obj.toString()}")
                    JsonParser.parseString(obj.toString())
                }
                is JSONObject -> {
                    // Convert org.json.JSONObject to Gson JsonElement
                    Log.d("NativeUIParser", "Converting JSONObject: ${obj.toString()}")
                    JsonParser.parseString(obj.toString())
                }
                else -> {
                    // For other types, use Gson's toJsonTree
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
