plugins {
    alias(libs.plugins.android.application)
    alias(libs.plugins.kotlin.android)
    alias(libs.plugins.kotlin.compose)
}

android {
    namespace = "com.sanhe.authcenter"
    compileSdk = 34

    defaultConfig {
        applicationId = "com.sanhe.authcenter"
        minSdk = 26
        targetSdk = 34
        versionCode = 1
        versionName = "1.0.0"

        // 默认服务器地址（可在 App 内设置页修改）
        buildConfigField("String", "DEFAULT_BASE_URL", "\"https://auth.sanhe.com.mp\"")
        // 默认回调地址（自定义 scheme，需与 AuthCenter 应用注册的 callback_url 一致）
        buildConfigField("String", "DEFAULT_REDIRECT_URI", "\"authcenter://callback\"")
        // 内置官方应用凭据（AuthCenter 内置，开箱即用，无需注册）
        buildConfigField("String", "DEFAULT_CLIENT_ID", "\"authcenter_android\"")
        buildConfigField("String", "DEFAULT_CLIENT_SECRET", "\"sk-gIByhNMcdkCETnJUs9ymbcHYyITgbUJq\"")
    }

    buildTypes {
        release {
            isMinifyEnabled = true
            isShrinkResources = true
            proguardFiles(
                getDefaultProguardFile("proguard-android-optimize.txt"),
                "proguard-rules.pro"
            )
        }
    }
    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }
    kotlinOptions {
        jvmTarget = "17"
    }
    buildFeatures {
        compose = true
        buildConfig = true
    }
}

dependencies {
    implementation(libs.androidx.core.ktx)
    implementation(libs.androidx.lifecycle.runtime.ktx)
    implementation(libs.androidx.lifecycle.viewmodel.compose)
    implementation(libs.androidx.lifecycle.runtime.compose)
    implementation(libs.androidx.activity.compose)

    implementation(platform(libs.androidx.compose.bom))
    implementation(libs.androidx.compose.ui)
    implementation(libs.androidx.compose.ui.graphics)
    implementation(libs.androidx.compose.ui.tooling.preview)
    implementation(libs.androidx.compose.material3)
    implementation(libs.androidx.compose.material.icons)

    implementation(libs.okhttp)
    implementation(libs.coil.compose)
    implementation(libs.androidx.datastore.preferences)

    debugImplementation(libs.androidx.compose.ui.tooling)
}
