<?php
/**
 * Staff onboard page template
 *
 * @package Timetics
 */

$user         = wp_get_current_user();
$integrations = timetics_get_staff_integrations( $user->ID );
?>

<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="UTF-8" />
        <meta http-equiv="X-UA-Compatible" content="IE=edge" />
        <meta name="viewport" content="width=device-width, initial-scale=1.0" />
        <title><?php esc_html_e( 'Staff onboard', 'timetics' ); ?></title>
        <link rel="preconnect" href="https://fonts.googleapis.com" />
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
        <link
            href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600&display=swap"
            rel="stylesheet"
        />
    </head>
    <style>
        body {
            box-sizing: border-box;
            background-color: #f4f5f7;
            font-family: 'Poppins', sans-serif;
            font-weight: 400;
        }
        ul {
            list-style: none;
            padding: 0;
            margin: 0;
        }
        h2,
        h3,
        h4 {
            margin: 0;
            font-weight: 500;
        }
        p {
            margin: 0;
            color: #00000073;
        }

        header {
            margin-bottom: 30px;
        }

        .onboard-wraper {
            height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .template-container {
            max-width: 690px;
            background-color: #ffffff;
            border-radius: 10px;
            padding: 30px 40px;
            margin: 0 auto;
            box-shadow: rgba(100, 100, 111, 0.2) 0px 7px 29px 0px;
        }
        .single-list {
            border-bottom: 1px solid #f0f0f0;
            margin-bottom: 20px;
        }
        .single-list:last-child {
            border-bottom: none;
            margin-bottom: 0;
        }
        .single-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 20px;
            flex-wrap: wrap;
            margin-bottom: 20px;
        }

        .integration-logo {
            width: 54px;
            height: 54px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            background-color: #edf4ff;
        }
        .connect-btn {
            background-color: #1890ff;
            border: none;
            padding: 10px 18px;
            border-radius: 5px;
            color: #fff;
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.645, 0.045, 0.355, 1);
            line-height: 1;
            text-decoration: none;
            font-size: 14px;
        }
        .connect-btn:hover {
            background: #40a9ff;
        }
        .skip-wrapper{
            text-align: right;
        }
        @media screen and (max-width: 767px) {
            .template-container {
                padding: 15px 25px;
            }
            .single-item {
                flex-direction: column;
                align-items: flex-start;
            }
        }
    </style>
    <body>
        <div class="onboard-wraper">
            <div class="template-container">
                <header><h2><?php esc_html_e( 'Integrations', 'timetics' ); ?></h2></header>
                <main class="main-wrapper">
                    <ul>
                        <?php foreach( $integrations as $integration ): ?>
                            <li class="single-list">
                                <div class="single-item">
                                    <div class="integration-logo">
                                        <?php 
                                        if($integration['id'] == 'google-calendar-meet'){
                                            ?>
                                            <svg
                                                    width="20"
                                                    height="20"
                                                    viewBox="0 0 20 20"
                                                    fill="none"
                                                    xmlns="http://www.w3.org/2000/svg"
                                                >
                                                    <path
                                                        d="M11.1816 10.4049L12.9362 12.4103L15.2955 13.9181L15.7068 10.4172L15.2955 6.99451L12.8909 8.31916L11.1816 10.4049Z"
                                                        fill="#00832D"
                                                    />
                                                    <path
                                                        d="M1 13.5931V16.5756C1 17.2575 1.55228 17.8097 2.23414 17.8097H5.21666L5.83373 15.5554L5.21666 13.5931L3.17004 12.976L1 13.5931Z"
                                                        fill="#0066DA"
                                                    />
                                                    <path
                                                        d="M5.21666 3L1 7.21666L3.17004 7.83373L5.21666 7.21666L5.82345 5.28111L5.21666 3Z"
                                                        fill="#E94235"
                                                    />
                                                    <path
                                                        d="M5.21666 7.21666H1V13.5931H5.21666V7.21666Z"
                                                        fill="#2684FC"
                                                    />
                                                    <path
                                                        d="M17.9902 4.78541L15.2956 6.99453V13.9181L18.0025 16.1375C18.4077 16.4542 19.0001 16.1652 19.0001 15.65V5.26261C19.0001 4.74118 18.3944 4.45527 17.9902 4.78541ZM11.1818 10.4049V13.5931H5.2168V17.8097H14.0615C14.7434 17.8097 15.2956 17.2575 15.2956 16.5756V13.9181L11.1818 10.4049Z"
                                                        fill="#00AC47"
                                                    />
                                                    <path
                                                        d="M14.0615 3H5.2168V7.21666H11.1818V10.4049L15.2956 6.99657V4.23414C15.2956 3.55228 14.7434 3 14.0615 3Z"
                                                        fill="#FFBA00"
                                                    />
                                                </svg>
                                            <?php
                                        } else if($integration['id'] == 'zoom-meeting'){
                                            ?>
                                                <svg
                                                        xmlns="http://www.w3.org/2000/svg"
                                                        width="28"
                                                        height="15"
                                                        viewBox="0 0 28 15"
                                                        fill="none"
                                                    >
                                                        <path
                                                            d="M0 0.753028V10.839C0.00914414 13.1197 1.87186 14.955 4.14337 14.9458H18.8445C19.2624 14.9458 19.5986 14.6096 19.5986 14.2008V4.11538C19.5894 1.83473 17.7273 -0.00109453 15.4552 0.00804961H0.754123C0.336182 0.00804961 0 0.344232 0 0.753028ZM20.5345 4.6877L26.6041 0.253328C27.1312 -0.182364 27.54 -0.0737101 27.54 0.716989V14.2369C27.54 15.1368 27.0403 15.0276 26.6041 14.7005L20.5345 10.2753V4.6877Z"
                                                            fill="#4A8CFF"
                                                        />
                                                    </svg>
                                            <?php
                                        } else if($integration['id'] == 'google-calendar'){
                                            ?>
                                            <svg
                                                    width="20"
                                                    height="20"
                                                    viewBox="0 0 20 20"
                                                    fill="none"
                                                    xmlns="http://www.w3.org/2000/svg"
                                                >
                                                    <path
                                                        d="M14.7388 5.26241L10.4758 4.78874L5.26526 5.26241L4.7915 9.99925L5.26517 14.7361L10.002 15.3282L14.7388 14.7361L15.2125 9.8809L14.7388 5.26241Z"
                                                        fill="white"
                                                    />
                                                    <path
                                                        d="M7.21058 12.6124C6.85653 12.3732 6.61137 12.0239 6.47754 11.562L7.29941 11.2233C7.37402 11.5075 7.50425 11.7278 7.69019 11.8841C7.87496 12.0404 8.09995 12.1174 8.36284 12.1174C8.63167 12.1174 8.86261 12.0357 9.05557 11.8722C9.24852 11.7088 9.34572 11.5003 9.34572 11.2482C9.34572 10.99 9.24384 10.7792 9.04018 10.6158C8.83651 10.4525 8.58073 10.3707 8.27518 10.3707H7.80035V9.55717H8.22658C8.48947 9.55717 8.71096 9.48616 8.89096 9.34405C9.07096 9.20194 9.16096 9.00772 9.16096 8.76022C9.16096 8.54 9.08041 8.36468 8.9194 8.23328C8.75839 8.10188 8.55463 8.03555 8.30713 8.03555C8.06557 8.03555 7.8737 8.09954 7.73159 8.2286C7.58948 8.35766 7.48643 8.51633 7.42136 8.70344L6.60786 8.36477C6.71559 8.05922 6.91341 7.78922 7.20347 7.55595C7.49363 7.32267 7.86425 7.2054 8.31424 7.2054C8.64697 7.2054 8.94658 7.26939 9.21189 7.39845C9.47712 7.52751 9.68556 7.70633 9.83595 7.93367C9.98634 8.16218 10.0609 8.41805 10.0609 8.70218C10.0609 8.99233 9.99111 9.2374 9.85134 9.43873C9.71157 9.64006 9.53985 9.79396 9.33618 9.90177V9.95028C9.60501 10.0628 9.82407 10.2345 9.99696 10.4654C10.1687 10.6964 10.2551 10.9723 10.2551 11.2944C10.2551 11.6165 10.1734 11.9043 10.0099 12.1565C9.84648 12.4088 9.62031 12.6077 9.33375 12.7521C9.04603 12.8966 8.72275 12.97 8.36392 12.97C7.94831 12.9712 7.56464 12.8516 7.21058 12.6124Z"
                                                        fill="#1A73E8"
                                                    />
                                                    <path
                                                        d="M12.2531 8.5348L11.3555 9.1873L10.9043 8.50285L12.5231 7.33521H13.1437V12.8429H12.2531V8.5348Z"
                                                        fill="#1A73E8"
                                                    />
                                                    <path
                                                        d="M14.7388 18.9995L19.0019 14.7364L16.8704 13.7891L14.7388 14.7364L13.7915 16.8679L14.7388 18.9995Z"
                                                        fill="#EA4335"
                                                    />
                                                    <path
                                                        d="M4.3125 16.8685L5.25983 19H14.7334V14.7369H5.25983L4.3125 16.8685Z"
                                                        fill="#34A853"
                                                    />
                                                    <path
                                                        d="M2.421 1C1.63593 1 1 1.63593 1 2.421V14.7367L3.13154 15.684L5.26308 14.7367V5.26308H14.7367L15.684 3.13154L14.7368 1H2.421Z"
                                                        fill="#4285F4"
                                                    />
                                                    <path
                                                        d="M1 14.7369V17.579C1 18.3641 1.63593 19 2.421 19H5.26308V14.7369H1Z"
                                                        fill="#188038"
                                                    />
                                                    <path
                                                        d="M14.7393 5.26249V14.7361H19.0023V5.26249L16.8708 4.31516L14.7393 5.26249Z"
                                                        fill="#FBBC04"
                                                    />
                                                    <path
                                                        d="M19.0023 5.26308V2.421C19.0023 1.63584 18.3664 1 17.5813 1H14.7393V5.26308H19.0023Z"
                                                        fill="#1967D2"
                                                    />
                                                </svg>
                                            <?php
                                        } ?>
                                    
                                    </div>
                                    <div class="content">
                                        <h3><?php echo esc_html( $integration['name'] ); ?></h3>
                                        <p>
                                            <?php echo esc_html( $integration['description'] ); ?>
                                        </p>
                                    </div>
                                    <a class="connect-btn" href="<?php echo esc_url( $integration['auth_url'] ); ?>"><?php esc_html_e( 'Connect', 'timetics' ); ?></a>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <div class="skip-wrapper">
                        <a  class="skip-button" id="timetics-onboard-skip" href="<?php echo esc_url( admin_url('admin.php?page=timetics#/my-profile') ); ?>"><?php esc_html_e( 'Skip', 'timetics' ); ?></a>
                    </div>

                    <script>
                        var onboard = document.getElementById('timetics-onboard-skip');

                        onboard.addEventListener('click', function(event) {
                            event.preventDefault();
                            let data = {
                            action: 'timetics_staff_onboard_skip'
                        }

                        fetch('<?php echo esc_url( admin_url('admin-ajax.php') ); ?>', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/x-www-form-urlencoded'
                            },
                            body: 'action=timetics_staff_onboard_skip'
,
                            })
                            .then(response => response.json())
                            .then(data => {
                                console.log('Response:', data);
                                // Handle the response data
                                window.location.href = "<?php echo esc_url( admin_url('admin.php?page=timetics#/my-profile') ); ?>";
                            })
                            .catch(error => {
                                console.error('Error:', error);
                                // Handle the error
                            });
                        });

                    </script>
                </main>
            </div>
        </div>
    </body>
</html>
