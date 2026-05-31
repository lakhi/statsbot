import { Component, output } from "@angular/core";
import { FormsModule, ReactiveFormsModule, UntypedFormBuilder, Validators } from "@angular/forms";

import { MatButtonModule } from "@angular/material/button";
import { MatCardModule } from "@angular/material/card";
import { MatFormFieldModule } from "@angular/material/form-field";
import { MatInputModule } from "@angular/material/input";
import { DataService } from "../data.service";
import { Student } from "../models/student";
import { Router } from "@angular/router";
import {MatCheckboxModule} from '@angular/material/checkbox';

@Component({
  selector: "app-register",
  templateUrl: "./register.component.html",
  standalone: true,
  imports: [MatInputModule, MatFormFieldModule, MatButtonModule, MatCardModule, FormsModule, ReactiveFormsModule, MatCheckboxModule],
  styleUrls: ["./register.component.css"],
})
export class RegisterComponent {

  registered = output<Student>();

  registrierungForm;

  success = false;
  registrationerror = false;
  errortext = "";

  constructor(
    private fb: UntypedFormBuilder,
    private dataService: DataService,
    private router: Router,
  )
  {
    this.registrierungForm = this.fb.group({
      dsgvo_check: [false, Validators.requiredTrue]
    });

   }



  onSubmit() {
    this.success = false;
    this.registrationerror = false;

    let student = new Student();

    this.dataService.registerStudent(student).subscribe(
      data => {

        this.registered.emit(data);

        this.success = true;
      },
    );
  }

}
