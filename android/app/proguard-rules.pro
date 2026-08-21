# AuthCenter Android app - ProGuard rules
# OkHttp
-dontwarn okhttp3.**
-dontwarn okio.**
# Keep JSON model classes
-keep class com.sanhe.authcenter.data.model.** { *; }
