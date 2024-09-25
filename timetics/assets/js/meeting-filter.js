jQuery(document).ready(function ($) {
    const category = $("#timetics-filter-category");
    const staff = $("#timetics-filter-staff");
    const url = timetics.site_url + "/wp-json/timetics/v1/appointments/filter";
    const meetinContainer = $(".tt-meeting-list-wrapper");

    $(category).on("change", function (e) {
        e.preventDefault();

        $.ajax({
            url: url,
            method: "GET",
            data: {
                category: $(this).val(),
            },
            success: function (res) {
                if (Array.isArray(res.data.items)) {
                    const meetings_markup = prepare_items(res.data.items);
                    meetinContainer.html(meetings_markup);
                }
            },
        });
    });

    $(staff).on("change", function (e) {
        e.preventDefault();

        $.ajax({
            url: url,
            method: "GET",
            data: {
                staff_id: $(this).val(),
            },
            success: function (res) {
                if (Array.isArray(res.data.items)) {
                    const meetings_markup = prepare_items(res.data.items);
                    meetinContainer.html(meetings_markup);
                }
            },
        });
    });

    /**
     * Prepare meeting itesm
     *
     * @return  string
     */
    function prepare_items(items) {
        if (items.length < 1) {
            return `<p>No meetings found</p>`;
        }

        const meetings = items
            .map((item) => {
                const staffs = preapare_staff(item.staff);
                return `
                <div class="tt-meeting-list-item">
                    <!-- Staff -->
                        ${staffs}
                    <h3 class="tt-title">
                        ${item.name}
                    </h3>
                    <!-- meeting description -->
                    <p>Meeting description</p>

                    <!-- meeting info -->
                    <ul class="meeting-info-list">
                        <!-- meeting duration -->
                        <li>
                            <svg width="20" height="20" viewBox="0 0 20 20" fill="CurrentColor" xmlns="http://www.w3.org/2000/svg">
                                <path fill-rule="evenodd" clip-rule="evenodd" d="M10 2C5.58172 2 2 5.58172 2 10C2 14.4183 5.58172 18 10 18C14.4183 18 18 14.4183 18 10C18 5.58172 14.4183 2 10 2ZM0 10C0 4.47715 4.47715 0 10 0C15.5228 0 20 4.47715 20 10C20 15.5228 15.5228 20 10 20C4.47715 20 0 15.5228 0 10ZM10 3.6C10.5523 3.6 11 4.04772 11 4.6V9.38197L14.0472 10.9056C14.5412 11.1526 14.7414 11.7532 14.4944 12.2472C14.2474 12.7412 13.6468 12.9414 13.1528 12.6944L9.55279 10.8944C9.214 10.725 9 10.3788 9 10V4.6C9 4.04772 9.44771 3.6 10 3.6Z"/>
                            </svg>
                            ${item.duration}
                        </li>
                        <!-- meeting price -->
                    </ul>
                    <button class="ant-btn ant-btn-primary ant-btn-block" data-id="${item.id}">
                        Book Now
                    </button>
                    <div class="timetics-booking-modal"></div>
                </div>`;
            })
            .join("");

        return meetings;
    }

    function preapare_staff(staffs) {
        const totalStaff = staffs.length;
        let markup = "";

        if (totalStaff > 2) {
            markup = `<ul class="tt-author tt-author-avatar-list">
                ${staffs
                    .map((staff) => {
                        return `
                    <li class="tt-single-host">
                        ${staff.image
                                ? `<img src="${staff.image}" alt="${staff.full_name}" />`
                                : ""
                            }
                        <div class="tt-tooltip-text">${staff.full_name}</div>
                    </li>
                    `;
                    })
                    .join("")}
            </ul>`;
        } else {
            markup = `<ul class="tt-author">
                ${staffs
                    .map((staff) => {
                        return `
                        <li>
                            ${staff.image
                                ? `<img src="${staff.image}" alt="${staff.full_name}" />`
                                : ""
                            }
                            <span>${staff.full_name}</span>
                        </li>`;
                    })
                    .join("")}
            </ul>`;
        }

        return markup;
    }
});
