#!/bin/bash

# =============================================================================
# SCRIPTS DE DESARROLLO - Politics Platform Backend
# =============================================================================

echo "🚀 Politics Platform Backend - Scripts de Desarrollo"
echo "======================================================"
echo ""

# Función para mostrar el menú
show_menu() {
    echo "Seleccione una opción:"
    echo ""
    echo "SETUP INICIAL:"
    echo "1)  Instalación completa (Docker + Composer + Migrations)"
    echo "2)  Solo composer install"
    echo "3)  Solo migrations"
    echo "4)  Generar claves (APP_KEY + JWT_SECRET)"
    echo ""
    echo "DESARROLLO:"
    echo "5)  Crear todos los Controllers"
    echo "6)  Crear todos los Requests"
    echo "7)  Crear todos los Resources"
    echo "8)  Crear todos los Middlewares"
    echo "9)  Crear todas las Policies"
    echo "10) Crear todos los Seeders"
    echo "11) Crear todos los Jobs"
    echo "12) Crear todos los Tests"
    echo ""
    echo "DOCKER:"
    echo "13) Iniciar Docker Compose"
    echo "14) Detener Docker Compose"
    echo "15) Ver logs"
    echo "16) Rebuild containers"
    echo ""
    echo "BASE DE DATOS:"
    echo "17) Ejecutar migrations"
    echo "18) Rollback migrations"
    echo "19) Fresh migrations con seed"
    echo "20) Ver status de migrations"
    echo ""
    echo "TESTING:"
    echo "21) Ejecutar todos los tests"
    echo "22) Tests con coverage"
    echo ""
    echo "0)  Salir"
    echo ""
    read -p "Opción: " option
}

# 1. Instalación completa
complete_install() {
    echo "📦 Instalación completa..."
    cp .env.example .env
    echo "✅ .env creado"
    
    docker-compose up -d
    echo "✅ Docker iniciado"
    
    docker-compose exec app composer install
    echo "✅ Dependencias instaladas"
    
    docker-compose exec app php artisan key:generate
    docker-compose exec app php artisan jwt:secret
    echo "✅ Claves generadas"
    
    docker-compose exec app php artisan migrate --seed
    echo "✅ Base de datos configurada"
    
    echo "🎉 Instalación completa!"
}

# 5. Crear todos los Controllers
create_controllers() {
    echo "📝 Creando Controllers..."
    
    php artisan make:controller Api/V1/AuthController
    php artisan make:controller Api/V1/TenantController --api --model=Tenant
    php artisan make:controller Api/V1/UserController --api --model=User
    php artisan make:controller Api/V1/MeetingController --api --model=Meeting
    php artisan make:controller Api/V1/MeetingTemplateController --api --model=MeetingTemplate
    php artisan make:controller Api/V1/MeetingAttendeeController --api
    php artisan make:controller Api/V1/CampaignController --api --model=Campaign
    php artisan make:controller Api/V1/CommitmentController --api --model=Commitment
    php artisan make:controller Api/V1/ResourceAllocationController --api --model=ResourceAllocation
    php artisan make:controller Api/V1/GeographyController --api
    
    echo "✅ Controllers creados"
}

# 6. Crear todos los Requests
create_requests() {
    echo "📝 Creando Requests..."
    
    php artisan make:request Api/V1/Auth/LoginRequest
    php artisan make:request Api/V1/Auth/RegisterRequest
    php artisan make:request Api/V1/Tenant/StoreTenantRequest
    php artisan make:request Api/V1/Tenant/UpdateTenantRequest
    php artisan make:request Api/V1/User/StoreUserRequest
    php artisan make:request Api/V1/User/UpdateUserRequest
    php artisan make:request Api/V1/Meeting/StoreMeetingRequest
    php artisan make:request Api/V1/Meeting/UpdateMeetingRequest
    php artisan make:request Api/V1/Meeting/StoreAttendeeRequest
    php artisan make:request Api/V1/Campaign/StoreCampaignRequest
    php artisan make:request Api/V1/Campaign/SendCampaignRequest
    php artisan make:request Api/V1/Commitment/StoreCommitmentRequest
    php artisan make:request Api/V1/Resource/StoreResourceAllocationRequest
    
    echo "✅ Requests creados"
}

# 7. Crear todos los Resources
create_resources() {
    echo "📝 Creando Resources..."
    
    php artisan make:resource Api/V1/UserResource
    php artisan make:resource Api/V1/TenantResource
    php artisan make:resource Api/V1/MeetingResource
    php artisan make:resource Api/V1/MeetingTemplateResource
    php artisan make:resource Api/V1/MeetingAttendeeResource
    php artisan make:resource Api/V1/CampaignResource
    php artisan make:resource Api/V1/CommitmentResource
    php artisan make:resource Api/V1/ResourceAllocationResource
    php artisan make:resource Api/V1/DepartmentResource
    php artisan make:resource Api/V1/CityResource
    
    echo "✅ Resources creados"
}

# 8. Crear Middlewares
create_middlewares() {
    echo "📝 Creando Middlewares..."
    
    php artisan make:middleware EnsureTenant
    php artisan make:middleware CheckSuperAdmin
    
    echo "✅ Middlewares creados"
}

# 9. Crear Policies
create_policies() {
    echo "📝 Creando Policies..."
    
    php artisan make:policy TenantPolicy --model=Tenant
    php artisan make:policy MeetingPolicy --model=Meeting
    php artisan make:policy CampaignPolicy --model=Campaign
    php artisan make:policy CommitmentPolicy --model=Commitment
    php artisan make:policy ResourceAllocationPolicy --model=ResourceAllocation
    
    echo "✅ Policies creadas"
}

# 10. Crear Seeders
create_seeders() {
    echo "📝 Creando Seeders..."
    
    php artisan make:seeder SuperAdminSeeder
    php artisan make:seeder RolesAndPermissionsSeeder
    php artisan make:seeder GeographySeeder
    php artisan make:seeder PrioritySeeder
    php artisan make:seeder DemoDataSeeder
    
    echo "✅ Seeders creados"
}

# 11. Crear Jobs
create_jobs() {
    echo "📝 Creando Jobs..."
    
    php artisan make:job Campaigns/SendCampaignJob
    php artisan make:job Meetings/GenerateQRCodeJob
    
    echo "✅ Jobs creados"
}

# 12. Crear Tests
create_tests() {
    echo "📝 Creando Tests..."
    
    php artisan make:test Api/V1/Auth/AuthTest
    php artisan make:test Api/V1/TenantTest
    php artisan make:test Api/V1/UserTest
    php artisan make:test Api/V1/MeetingTest
    php artisan make:test Api/V1/CampaignTest
    
    echo "✅ Tests creados"
}

# Loop principal
while true; do
    show_menu
    
    case $option in
        1) complete_install ;;
        2) composer install ;;
        3) php artisan migrate ;;
        4) php artisan key:generate && php artisan jwt:secret ;;
        5) create_controllers ;;
        6) create_requests ;;
        7) create_resources ;;
        8) create_middlewares ;;
        9) create_policies ;;
        10) create_seeders ;;
        11) create_jobs ;;
        12) create_tests ;;
        13) docker-compose up -d ;;
        14) docker-compose down ;;
        15) docker-compose logs -f app ;;
        16) docker-compose up -d --build ;;
        17) docker-compose exec app php artisan migrate ;;
        18) docker-compose exec app php artisan migrate:rollback ;;
        19) docker-compose exec app php artisan migrate:fresh --seed ;;
        20) docker-compose exec app php artisan migrate:status ;;
        21) docker-compose exec app php artisan test ;;
        22) docker-compose exec app php artisan test --coverage ;;
        0) echo "👋 Adiós!"; exit 0 ;;
        *) echo "❌ Opción inválida" ;;
    esac
    
    echo ""
    read -p "Presione Enter para continuar..."
    clear
done
