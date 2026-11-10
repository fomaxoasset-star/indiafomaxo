plugins {
    id("com.android.application")
    id("org.jetbrains.kotlin.android")
}

// Release signing comes from GitHub secrets (never stored in the repository).
val ksFile = System.getenv("FOMAXO_KEYSTORE_FILE")

android {
    namespace = "in.fomaxo.pos"
    compileSdk = 35

    defaultConfig {
        applicationId = "in.fomaxo.pos"
        minSdk = 24
        targetSdk = 35
        versionCode = (System.getenv("FOMAXO_VERSION_CODE") ?: "1").toInt()
        versionName = System.getenv("FOMAXO_VERSION_NAME") ?: "1.0"
    }

    signingConfigs {
        if (ksFile != null) {
            create("release") {
                storeFile = file(ksFile)
                storePassword = System.getenv("FOMAXO_KEYSTORE_PASSWORD")
                keyAlias = System.getenv("FOMAXO_KEY_ALIAS")
                keyPassword = System.getenv("FOMAXO_KEY_PASSWORD")
            }
        }
    }

    buildTypes {
        release {
            isMinifyEnabled = false
            if (ksFile != null) signingConfig = signingConfigs.getByName("release")
        }
    }

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }
    kotlinOptions { jvmTarget = "17" }
}

dependencies {
    implementation("androidx.core:core-ktx:1.13.1")
    implementation("androidx.activity:activity-ktx:1.9.3")
    implementation("androidx.webkit:webkit:1.12.1")
}
